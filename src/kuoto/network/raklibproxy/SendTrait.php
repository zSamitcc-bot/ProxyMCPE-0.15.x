<?php

namespace kuoto\network\raklibproxy;

/**
 * Envio de paquetes encapsulados al cliente (fragmentacion, ACK/NACK)
 * y el helper de bajo nivel para mandar UDP crudo (sendToClient, ya
 * incluido en el rango de mas abajo).
 *
 * Extraido de RakLibProxy para mantener ese archivo por debajo de un
 * tamano manejable. No cambia ningun comportamiento.
 */
trait SendTrait
{
    /**
     * Nudge an idle-but-connected session so its RakNet stack has to ACK
     * something, refreshing lastActivity without waiting for the client to
     * originate traffic on its own (see the comment above the call site in
     * RakLibProxy::tick()).
     *
     * Only fires for fully connected sessions, is throttled so it doesn't
     * spam a reliable packet every single proxy tick, and is harmless even
     * if the receiving end forwards the 1-byte payload up to the backend as
     * "game data" -- it isn't a valid MCPE packet ID a real client sends, so
     * it's simply ignored there.
     */
    private function maybeSendKeepalive(&$session, $now)
    {
        if (!isset($session['state']) || $session['state'] !== self::STATE_CONNECTED) {
            return;
        }

        if ($now - $session['lastActivity'] < self::KEEPALIVE_AFTER_IDLE) {
            return;
        }

        if (isset($session['lastKeepaliveSent']) && ($now - $session['lastKeepaliveSent']) < self::KEEPALIVE_MIN_INTERVAL) {
            return;
        }

        $session['lastKeepaliveSent'] = $now;

        // Raw RakNet CONNECTED_PING (0x00) + a ping id -- if the client speaks
        // real RakNet it'll reply with CONNECTED_PONG on its own, but what we
        // actually rely on here is reliability: RELIABLE forces the client's
        // RakNet layer to ACK this datagram's sequence number regardless of
        // whether it understands the payload.
        $ping = chr(0x00) . $this->packLong((int) ($now * 1000));
        $this->sendEncapsulated($session, $ping, self::RELIABLE);
    }

    private function sendEncapsulated(&$session, $buffer, $reliability = self::RELIABLE_ORDERED)
    {
        if (strlen($buffer) === 0) return;

        // Max payload per fragment: MTU - IP(20) - UDP(8) - DATA_PACKET header(4) - encapsulated header(max ~20)
        $maxPayload = $session['mtu'] - 52;
        if ($maxPayload < 512) $maxPayload = 512;

        // Split into fragments if needed
        $chunks = [];
        if (strlen($buffer) > $maxPayload) {
            $offset = 0;
            while ($offset < strlen($buffer)) {
                $len = min($maxPayload, strlen($buffer) - $offset);
                $chunks[] = substr($buffer, $offset, $len);
                $offset += $len;
            }
        } else {
            $chunks[] = $buffer;
        }

        $splitID = $session['nextSplitId']++;
        $splitCount = count($chunks);
        $orderIndex = $session['orderIndex'];
        $baseMessageIndex = $session['messageIndex'];

        // Increment messageIndex for each encapsulated packet sent
        if ($reliability >= self::RELIABLE) {
            $session['messageIndex']++;
        }

        foreach ($chunks as $splitIndex => $chunk) {
            $flags = ($reliability << 5);
            $dataSeq = $session['sendSeqNumber'];
            $session['sendSeqNumber']++;

            // Each fragment after the first gets its OWN messageIndex (matching real RakLib)
            if ($splitIndex > 0 && $reliability >= self::RELIABLE) {
                $fragmentMessageIndex = $session['messageIndex']++;
            } else {
                $fragmentMessageIndex = $baseMessageIndex;
            }

            // Has split flag
            if ($splitCount > 1) {
                $flags |= 0x10;
            }

            // Build encapsulated packet header
            $encapsulated = chr($flags);
            $encapsulated .= pack("n", strlen($chunk) * 8);

            // Message index for reliable
            if ($reliability >= self::RELIABLE) {
                $encapsulated .= $this->writeLTriad($fragmentMessageIndex);
            }

            // Order index + channel for RELIABLE_ORDERED
            if ($reliability === self::RELIABLE_ORDERED || $reliability === self::RELIABLE_SEQUENCED || $reliability === self::UNRELIABLE_SEQUENCED) {
                $encapsulated .= $this->writeLTriad($orderIndex);
                $encapsulated .= chr(0); // order channel
            }

            // Split header
            if ($splitCount > 1) {
                $encapsulated .= pack("N", $splitCount);
                $encapsulated .= pack("n", $splitID);
                $encapsulated .= pack("N", $splitIndex);
            }

            $encapsulated .= $chunk;

            // Wrap in DATA_PACKET (0x80 + seqNumber % 4)
            $dataPacket = chr(0x80 + ($dataSeq % 4));
            $dataPacket .= $this->writeLTriad($dataSeq);
            $dataPacket .= $encapsulated;

            $sent = @socket_sendto($this->socket, $dataPacket, strlen($dataPacket), 0, $session['address'], $session['port']);
            if ($sent === false) {
                $this->logger->debug("UDP send FAILED to {$session['address']}:{$session['port']}");
            }
            // Store in recovery queue for retransmission on NACK
            $session['recoveryQueue'][$dataSeq] = $dataPacket;
            // Solo se guardan los ultimos RECOVERY_QUEUE_MAX datagramas: los ACK
            // perdidos (o un cliente que deja de responder) dejaban aqui paquetes
            // de hasta ~1.4 KB acumulandose sin limite. Las claves son el numero
            // de secuencia de envio, asi que el mas viejo se localiza directamente.
            unset($session['recoveryQueue'][$dataSeq - self::RECOVERY_QUEUE_MAX]);
        }

        if ($splitCount > 1) {
            $this->logger->debug("Sent {$splitCount} split fragments to {$session['address']}:{$session['port']} splitID={$splitID}");
        }

        // Increment orderIndex after all fragments of this ordered packet are sent
        if ($reliability === self::RELIABLE_ORDERED) {
            $session['orderIndex'] = $orderIndex + 1;
        }
    }

    /**
     * Send ACK for received packets
     */
    private function sendAck(&$session, $packets)
    {
        sort($packets, SORT_NUMERIC);

        $payload = "";
        $count = count($packets);
        $records = 0;

        if ($count > 0) {
            $pointer = 1;
            $start = $packets[0];
            $last = $packets[0];

            while ($pointer < $count) {
                $current = $packets[$pointer++];
                $diff = $current - $last;
                if ($diff === 1) {
                    $last = $current;
                } elseif ($diff > 1) {
                    if ($start === $last) {
                        $payload .= "\x01";
                        $payload .= $this->writeLTriad($start);
                        $start = $last = $current;
                    } else {
                        $payload .= "\x00";
                        $payload .= $this->writeLTriad($start);
                        $payload .= $this->writeLTriad($last);
                        $start = $last = $current;
                    }
                    $records++;
                }
            }

            if ($start === $last) {
                $payload .= "\x01";
                $payload .= $this->writeLTriad($start);
            } else {
                $payload .= "\x00";
                $payload .= $this->writeLTriad($start);
                $payload .= $this->writeLTriad($last);
            }
            $records++;
        }

        $ack = chr(self::PKT_ACK);
        $ack .= pack("n", $records);
        $ack .= $payload;

        @socket_sendto($this->socket, $ack, strlen($ack), 0, $session['address'], $session['port']);
    }

    /**
     * Handle ACK from client
     */
    private function handleAck($key, $data)
    {
        if (!isset($this->sessions[$key])) return;
        $session = &$this->sessions[$key];
        $session['lastActivity'] = microtime(true);

        if (strlen($data) < 3) return;
        $count = unpack('n', substr($data, 1, 2))[1];
        $offset = 3;
        $acked = 0;
        for ($i = 0; $i < $count && $offset < strlen($data); $i++) {
            $type = ord($data[$offset]);
            $offset++;
            if ($type === 0) {
                // Range: start(3) + end(3)
                if ($offset + 6 > strlen($data)) break;
                $start = $this->readLTriad($data, $offset);
                $offset += 3;
                $end = $this->readLTriad($data, $offset);
                $offset += 3;
                for ($j = $start; $j <= $end; $j++) {
                    $acked++;
                    unset($session['recoveryQueue'][$j]);
                }
            } else {
                // Single: triad(3)
                if ($offset + 3 > strlen($data)) break;
                $seq = $this->readLTriad($data, $offset);
                $offset += 3;
                $acked++;
                unset($session['recoveryQueue'][$seq]);
            }
        }
        // Uncomment for debugging ACK issues:
        // $this->logger->info("ACK from {$session['address']}:{$session['port']} count=" . $acked);
    }

    /**
     * Handle NACK from client - retransmit lost packets
     */
    private function handleNack($key, $data)
    {
        if (!isset($this->sessions[$key])) return;
        $session = &$this->sessions[$key];
        $session['lastActivity'] = microtime(true);

        if (strlen($data) < 3) return;
        $count = unpack('n', substr($data, 1, 2))[1];
        $offset = 3;
        $retransmitted = 0;
        for ($i = 0; $i < $count && $offset < strlen($data); $i++) {
            $type = ord($data[$offset]);
            $offset++;
            if ($type === 0) {
                if ($offset + 6 > strlen($data)) break;
                $start = $this->readLTriad($data, $offset);
                $offset += 3;
                $end = $this->readLTriad($data, $offset);
                $offset += 3;
                for ($j = $start; $j <= $end; $j++) {
                    if (isset($session['recoveryQueue'][$j])) {
                        $pk = $session['recoveryQueue'][$j];
                        @socket_sendto($this->socket, $pk, strlen($pk), 0, $session['address'], $session['port']);
                        $retransmitted++;
                    }
                }
            } else {
                if ($offset + 3 > strlen($data)) break;
                $seq = $this->readLTriad($data, $offset);
                $offset += 3;
                if (isset($session['recoveryQueue'][$seq])) {
                    $pk = $session['recoveryQueue'][$seq];
                    @socket_sendto($this->socket, $pk, strlen($pk), 0, $session['address'], $session['port']);
                    $retransmitted++;
                }
            }
        }
        if ($retransmitted > 0) {
            $this->logger->debug("NACK from {$session['address']}:{$session['port']} retransmitted {$retransmitted} packets");
        }
    }

    /**
     * Send data to a client via UDP (raw)
     */
    public function sendToClient($address, $port, $data)
    {
        if (!$this->running) {
            return false;
        }
        $sent = @socket_sendto($this->socket, $data, strlen($data), 0, $address, $port);
        return $sent !== false;
    }

    /**
     * Handle data from backend server (via RedirectPacket)
     * Forward it to the correct client as encapsulated data
     */
}