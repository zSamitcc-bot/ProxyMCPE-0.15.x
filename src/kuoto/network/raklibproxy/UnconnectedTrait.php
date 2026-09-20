<?php

namespace kuoto\network\raklibproxy;

use kuoto\protocol\ChangeDimensionPacket;
use kuoto\event\proxy\ProxyPingEvent;

/**
 * Fase "unconnected" del handshake RakLib: ping/pong (MOTD) y el
 * intercambio OPEN_CONNECTION_REQUEST/REPLY 1 y 2 que crea la sesion.
 *
 * Extraido de RakLibProxy para mantener ese archivo por debajo de un
 * tamano manejable. No cambia ningun comportamiento: sigue operando
 * sobre las mismas propiedades y constantes de RakLibProxy via $this.
 */
trait UnconnectedTrait
{
    private function handlePing($data, $address, $port)
    {
        if (strlen($data) < 25) {
            return;
        }

        $timeBytes = substr($data, 1, 8);
        $receivedMagic = substr($data, 9, 16);
        if ($receivedMagic !== self::getMAGIC()) {
            return;
        }

        // El evento permite a un listener cambiar MOTD, contadores o incluso
        // hacer que el proxy no responda al ping (cancelandolo).
        $event = new ProxyPingEvent(
            $address,
            $port,
            $this->motdName,
            $this->subMotd,
            $this->manager->getPlayerCount(),
            $this->maxPlayers,
            $this->gamemode
        );
        $event->call();
        if ($event->isCancelled()) {
            return;
        }

        $gamemodeInt = ($this->gamemode === 'Creative') ? 1 : (($this->gamemode === 'Adventure') ? 2 : 0);

        $motd = "MCPE;"
            . $event->getMotd() . ";"
            . $this->protocolVersion . ";"
            . $this->versionName . ";"
            . $event->getPlayerCount() . ";"
            . $event->getMaxPlayers() . ";"
            . $this->serverGuid . ";"
            . $event->getSubMotd() . ";"
            . $this->gamemode . ";"
            . $gamemodeInt . ";"
            . $this->port . ";"
            . $this->port . ";";

        // UNCONNECTED_PONG: ID(1) + PingID(8) + ServerID(8) + MAGIC(16) + StrLen(2) + String
        $pong = chr(self::PKT_UNCONNECTED_PONG);
        $pong .= $timeBytes;
        $pong .= $this->packLong($this->serverGuid);
        $pong .= self::getMAGIC();
        $pong .= pack("n", strlen($motd));
        $pong .= $motd;

        @socket_sendto($this->socket, $pong, strlen($pong), 0, $address, $port);
        $this->logger->debug("Pong -> {$address}:{$port} motd={$motd}");
    }

    /**
     * OPEN_CONNECTION_REQUEST_1 -> OPEN_CONNECTION_REPLY_1
     * Format: ID(1) + MAGIC(16) + serverID(8) + security(1) + mtu(2) = 28 bytes
     */
    private function handleRequest1($data, $address, $port)
    {
        if (strlen($data) < 18) {
            return;
        }

        $magic = substr($data, 1, 16);
        if ($magic !== self::getMAGIC()) {
            return;
        }

        // If this address:port already has a fully connected session, this is a
        // stray/duplicate REQUEST_1 (retransmission racing with our earlier reply,
        // or a leftover from the client's MTU-probing burst) -- do not let it kick
        // off a second parallel handshake that would eventually overwrite the live
        // session in $this->sessions and reset its state (backendHash, uuid, etc).
        $key = $address . ':' . $port;
        if (isset($this->sessions[$key]) && $this->sessions[$key]['state'] === self::STATE_CONNECTED) {
            return;
        }

        // Decode: skip ID(1) + MAGIC(16) + protocol(1) = offset 18
        // MTU = total packet length + 28 bytes IP/UDP header overhead.
        // The client pads its REQUEST_1 payload to (desiredMtu - 28) bytes,
        // expecting the server to add the 28 back when echoing the MTU in
        // REPLY_1. If we don't add it here, the returned MTU is smaller than
        // what the client asked for, the client rejects REPLY_1 as invalid,
        // and it just retries REQUEST_1 with the next smaller candidate MTU
        // (1492 -> 1200 -> 576) forever, never reaching REQUEST_2.
        $mtu = strlen($data) + 28;

        // OPEN_CONNECTION_REPLY_1
        $reply = chr(self::PKT_OPEN_REPLY_1);         // ID
        $reply .= self::getMAGIC();                      // 16 bytes
        $reply .= $this->packLong($this->serverGuid);  // 8 bytes
        $reply .= chr(0);                              // security = 0
        $reply .= pack("n", $mtu);                     // 2 bytes MTU

        @socket_sendto($this->socket, $reply, strlen($reply), 0, $address, $port);
        $this->logger->debug("Reply1 -> {$address}:{$port} ({$mtu}b) hex=" . bin2hex($reply));
    }

    /**
     * OPEN_CONNECTION_REQUEST_2 -> OPEN_CONNECTION_REPLY_2 + create session
     */
    private function handleRequest2($data, $address, $port)
    {
        if (strlen($data) < 34) {
            $this->logger->debug("REQUEST_2 too short: " . strlen($data) . " bytes");
            return;
        }

        // Same reasoning as handleRequest1(): ignore a stray REQUEST_2 once we
        // already have a fully connected session for this address:port, so it
        // can't stomp the live session (and its uuid/backendHash) with a fresh one.
        $key = $address . ':' . $port;
        if (isset($this->sessions[$key]) && $this->sessions[$key]['state'] === self::STATE_CONNECTED) {
            return;
        }

        $offset = 1;
        $offset += 16; // magic
        // Server address: version(1) + ip(4) + port(2) = 7 bytes
        $addrVersion = ord($data[$offset]);
        $offset++;
        $ip = ((~ord($data[$offset])) & 0xff) . "." . ((~ord($data[$offset+1])) & 0xff) . "." . ((~ord($data[$offset+2])) & 0xff) . "." . ((~ord($data[$offset+3])) & 0xff);
        $offset += 4;
        $serverPort = (ord($data[$offset]) << 8) | ord($data[$offset+1]);
        $offset += 2;
        $mtu = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;
        $clientGuid = $this->unpackLong(substr($data, $offset, 8));

        // OPEN_CONNECTION_REPLY_2
        $reply = chr(self::PKT_OPEN_REPLY_2);
        $reply .= self::getMAGIC();
        $reply .= $this->packLong($this->serverGuid);

        // Client address (7 bytes)
        $parts = explode(".", $address);
        $reply .= chr(4); // IPv4
        foreach ($parts as $p) {
            $reply .= chr((~((int)$p)) & 0xff);
        }
        $reply .= pack("n", $port);
        $reply .= pack("n", $mtu);
        $reply .= chr(0); // no encryption

        @socket_sendto($this->socket, $reply, strlen($reply), 0, $address, $port);

        $key = $address . ':' . $port;

        // Only create a session the FIRST time we see a valid REQUEST_2 for this
        // address:port. The client's own MTU-probing burst can (and does) send
        // several REQUEST_1/REQUEST_2 pairs back-to-back before processing any
        // reply (especially over loopback, where RTT is ~0). Recreating the
        // session array on every single REQUEST_2 -- which is what happened
        // before this fix -- reset sendSeqNumber/orderIndex/messageIndex back to
        // 0 each time, so whichever REQUEST_2 arrived last "won" with a fresh,
        // zeroed session just as the client was about to send CLIENT_CONNECT
        // against the sequence numbers from an earlier (now-discarded) session.
        // That mismatch is why the handshake could silently stall with no
        // CLIENT_CONNECT / SERVER_HANDSHAKE ever appearing in the log, even
        // though "Session opened" printed several times. Re-sending REPLY_2
        // for an already-known session is enough -- RakNet's offline messages
        // are meant to be idempotent retransmissions, not new connections.
        if (isset($this->sessions[$key])) {
            $this->logger->debug("Duplicate REQUEST_2 for existing session {$key}, re-sent REPLY_2 without resetting state");
            return;
        }

        // Create session
        $this->nextSessionId++;
        $uuid = pack('NNNN', $this->nextSessionId, mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF));
        $uuidHex = bin2hex($uuid);

        $this->sessions[$key] = array(
            'state' => self::STATE_CONNECTING_2,
            'lastActivity' => microtime(true),
            'backendHash' => null,
            'mtu' => $mtu,
            'uuid' => $uuid,
            'uuidHex' => $uuidHex,
            'address' => $address,
            'port' => $port,
            'clientGuid' => $clientGuid,
            'sendSeqNumber' => 0,
            'lastSeqNumber' => -1,
            'windowStart' => 0,
            'windowEnd' => 2048,
            'receivedWindow' => [],
            'ackQueue' => [],
            'nackQueue' => [],
            'recoveryQueue' => [],
            'splitPackets' => [],
            'loginPacket' => null,
            'pendingBuffers' => [],
            'nextSplitId' => 0,
            'orderIndex' => 0,
            'messageIndex' => 0,
            'dimension' => ChangeDimensionPacket::DIMENSION_NORMAL,
        );

        $this->logger->info("Sesion abierta: {$address}:{$port} mtu={$mtu} uuid={$uuidHex}");
    }
}
