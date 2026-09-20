<?php

namespace kuoto\network\raklibproxy;

/**
 * Fase "connected": decodificacion de los data packets RakLib
 * (ventana de recepcion, split packets) y de los paquetes internos
 * encapsulados (CLIENT_CONNECT / CLIENT_HANDSHAKE).
 *
 * Extraido de RakLibProxy para mantener ese archivo por debajo de un
 * tamano manejable. No cambia ningun comportamiento.
 */
trait DataPacketTrait
{
    private function handleDataPacket($key, $data)
    {
        if (!isset($this->sessions[$key])) {
            return;
        }

        $session = &$this->sessions[$key];
        $session['lastActivity'] = microtime(true);

        $packetId = ord($data[0]);

        // Data packets: ID(1) + seqNumber(3 LTriad) + encapsulated packets...
        if (strlen($data) < 4) {
            return;
        }

        $seqNumber = $this->readLTriad($data, 1);

        // Add to ACK queue
        $session['ackQueue'][] = $seqNumber;

        // Check for duplicates
        if (isset($session['receivedWindow'][$seqNumber])) {
            return;
        }
        $session['receivedWindow'][$seqNumber] = true;

        // receivedWindow solo sirve para descartar retransmisiones recientes,
        // pero antes crecia una entrada por datagrama durante toda la vida de
        // la sesion. Un array de PHP conserva el orden de insercion, asi que
        // basta quedarse con las ultimas entradas cuando se pasa del limite.
        if (count($session['receivedWindow']) > self::RECEIVED_WINDOW_MAX) {
            $session['receivedWindow'] = array_slice($session['receivedWindow'], -self::RECEIVED_WINDOW_KEEP, null, true);
        }

        // Update window
        if ($seqNumber >= $session['windowStart'] && $seqNumber <= $session['windowEnd']) {
            $diff = $seqNumber - $session['lastSeqNumber'];
            if ($diff >= 1) {
                $session['lastSeqNumber'] = $seqNumber;
                $session['windowStart'] += $diff;
                $session['windowEnd'] += $diff;
            }
        }

        // Parse encapsulated packets from offset 4
        $offset = 4;
        while ($offset < strlen($data)) {
            $encapsulated = $this->decodeEncapsulatedPacket($data, $offset);
            if ($encapsulated === null || strlen($encapsulated['buffer']) === 0) {
                break;
            }

            $this->handleEncapsulatedPacket($key, $session, $encapsulated);
        }
    }

    /**
     * Decode an encapsulated packet from binary data
     * Returns ['reliability' => int, 'hasSplit' => bool, 'messageIndex' => int|null,
     *          'orderIndex' => int|null, 'orderChannel' => int|null, 'buffer' => string]
     */
    private function decodeEncapsulatedPacket($data, &$offset)
    {
        if ($offset >= strlen($data)) {
            return null;
        }

        $flags = ord($data[$offset]);
        $offset++;
        $reliability = ($flags >> 5) & 0x07;
        $hasSplit = ($flags & 0x10) > 0;

        // Length in bits
        if ($offset + 2 > strlen($data)) {
            return null;
        }
        $lengthBits = $this->readShort($data, $offset);
        $offset += 2;
        $length = (int)ceil($lengthBits / 8);

        $messageIndex = null;
        $orderIndex = null;
        $orderChannel = null;

        if ($reliability > self::UNRELIABLE) {
            if ($reliability >= self::RELIABLE && $reliability !== 0 /* UNRELIABLE_WITH_ACK_RECEIPT */) {
                if ($offset + 3 <= strlen($data)) {
                    $messageIndex = $this->readLTriad($data, $offset);
                    $offset += 3;
                }
            }
            if ($reliability <= self::RELIABLE_SEQUENCED && $reliability !== self::RELIABLE) {
                if ($offset + 4 <= strlen($data)) {
                    $orderIndex = $this->readLTriad($data, $offset);
                    $offset += 3;
                    $orderChannel = ord($data[$offset]);
                    $offset += 1;
                }
            }
        }

        $splitCount = null;
        $splitID = null;
        $splitIndex = null;

        if ($hasSplit) {
            if ($offset + 10 <= strlen($data)) {
                $splitCount = $this->readInt($data, $offset);
                $offset += 4;
                $splitID = $this->readShort($data, $offset);
                $offset += 2;
                $splitIndex = $this->readInt($data, $offset);
                $offset += 4;
            }
        }

        if ($offset + $length > strlen($data)) {
            $length = strlen($data) - $offset;
        }

        $buffer = substr($data, $offset, $length);
        $offset += $length;

        return array(
            'reliability' => $reliability,
            'hasSplit' => $hasSplit,
            'messageIndex' => $messageIndex,
            'orderIndex' => $orderIndex,
            'orderChannel' => $orderChannel,
            'splitCount' => $splitCount,
            'splitID' => $splitID,
            'splitIndex' => $splitIndex,
            'buffer' => $buffer,
        );
    }

    /**
     * Handle an encapsulated packet
     */
    private function handleEncapsulatedPacket($key, &$session, $encapsulated)
    {
        // Handle split packets
        if ($encapsulated['hasSplit']) {
            $this->handleSplitPacket($key, $session, $encapsulated);
            return;
        }

        $buffer = $encapsulated['buffer'];
        if (strlen($buffer) === 0) {
            return;
        }

        $id = ord($buffer[0]);
        if ($this->logger->isDebugEnabled()) {
            $this->logger->debug("Encapsulated from {$session['address']}:{$session['port']} id=0x" . dechex($id) . " len=" . strlen($buffer) . " state=" . $session['state']);
        }

        // In CONNECTING_2 state, handle RakLib internal packets
        if ($session['state'] === self::STATE_CONNECTING_2 && $id === 0x09) {
            $this->handleInternalPacket($key, $session, $id, $buffer);
        } elseif ($session['state'] === self::STATE_CONNECTING_2 && $id === 0x13) {
            $this->handleInternalPacket($key, $session, $id, $buffer);
        } elseif ($session['state'] === self::STATE_CONNECTED && $id === 0x15) {
            $this->logger->info("Cliente desconectado: {$session['address']}:{$session['port']}");
            $this->handlePlayerDisconnect($key, $session);
        } else {
            // ALL other packets are MCPE game data - forward to backend
            $this->forwardGameData($key, $session, $buffer);
        }
    }

    /**
     * Handle split packets - reassemble
     */
    private function handleSplitPacket($key, &$session, $encapsulated)
    {
        $splitID = $encapsulated['splitID'];
        $splitIndex = $encapsulated['splitIndex'];
        $splitCount = $encapsulated['splitCount'];

        if ($splitCount >= 128 || $splitIndex < 0) {
            return;
        }

        if (!isset($session['splitPackets'][$splitID])) {
            if (count($session['splitPackets']) >= 4) {
                return;
            }
            $session['splitPackets'][$splitID] = [];
        }

        $session['splitPackets'][$splitID][$splitIndex] = $encapsulated['buffer'];

        if (count($session['splitPackets'][$splitID]) === $splitCount) {
            // Reassemble
            $buffer = '';
            for ($i = 0; $i < $splitCount; $i++) {
                if (isset($session['splitPackets'][$splitID][$i])) {
                    $buffer .= $session['splitPackets'][$splitID][$i];
                }
            }
            unset($session['splitPackets'][$splitID]);

            // Process reassembled packet
            if (strlen($buffer) > 0) {
                $id = ord($buffer[0]);
                if ($id < 0x80) {
                    $this->handleInternalPacket($key, $session, $id, $buffer);
                } else {
                    $this->forwardGameData($key, $session, $buffer);
                }
            }
        }
    }

    /**
     * Handle internal RakLib packets (CLIENT_CONNECT, CLIENT_HANDSHAKE, etc.)
     */
    private function handleInternalPacket($key, &$session, $id, $buffer)
    {
        switch ($session['state']) {
            case self::STATE_CONNECTING_2:
                if ($id === 0x09) { // CLIENT_CONNECT
                    $this->handleClientConnect($key, $session, $buffer);
                } elseif ($id === 0x13) { // CLIENT_HANDSHAKE
                    $this->handleClientHandshake($key, $session, $buffer);
                }
                break;

            case self::STATE_CONNECTED:
                if ($id === 0x15) { // CLIENT_DISCONNECT
                    $this->logger->info("Cliente desconectado: {$session['address']}:{$session['port']}");
                    $this->handlePlayerDisconnect($key, $session);
                }
                break;
        }
    }

    /**
     * CLIENT_CONNECT (0x09) -> respond with SERVER_HANDSHAKE (0x10)
     */
    private function handleClientConnect($key, &$session, $buffer)
{
    if (strlen($buffer) < 18) {
        return;
    }

    $clientID = $this->unpackLong(substr($buffer, 1, 8));
    $sendPing = $this->unpackLong(substr($buffer, 9, 8));
    $useSecurity = ord($buffer[17]) > 0;

    $reply = chr(self::PKT_SERVER_HANDSHAKE);

    $parts = explode(".", $session['address']);

    $reply .= chr(4);

    foreach ($parts as $p) {
        $reply .= chr((~((int) $p)) & 0xff);
    }

    $reply .= pack("n", $session['port']);

    $systemAddresses = array(
        array("127.0.0.1", 0, 4),
        array("0.0.0.0", 0, 4),
        array("0.0.0.0", 0, 4),
        array("0.0.0.0", 0, 4),
        array("0.0.0.0", 0, 4),
        array("0.0.0.0", 0, 4),
        array("0.0.0.0", 0, 4),
        array("0.0.0.0", 0, 4),
        array("0.0.0.0", 0, 4),
        array("0.0.0.0", 0, 4)
    );

    foreach ($systemAddresses as $addr) {
        $addrParts = explode(".", $addr[0]);

        $reply .= chr($addr[2]);

        foreach ($addrParts as $p) {
            $reply .= chr((~((int) $p)) & 0xff);
        }

        $reply .= pack("n", $addr[1]);
    }

    $reply .= $this->packLong($sendPing);
    $reply .= $this->packLong($sendPing + 1000);

    $this->sendEncapsulated(
        $session,
        $reply,
        self::UNRELIABLE
    );

    $this->logger->debug(
        "SERVER_HANDSHAKE enviado a " .
        $session['address'] .
        ":" .
        $session['port'] .
        " len=" .
        strlen($reply) .
        " hex=" .
        bin2hex($reply)
    );
}

    /**
     * CLIENT_HANDSHAKE (0x13) -> mark session as CONNECTED
     */
    private function handleClientHandshake($key, &$session, $buffer)
    {
        $session['state'] = self::STATE_CONNECTED;
        $this->logger->info("Cliente conectado: {$session['address']}:{$session['port']} uuid={$session['uuidHex']}");

        // Don't assign to server yet - wait for the first real MCPE game packet
        // which contains the login data the PocketMine backend needs
    }

}
