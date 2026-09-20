<?php

namespace kuoto\network;

use kuoto\server\ServerManager;
use kuoto\utils\Logger;
use kuoto\raklib\Binary;

use kuoto\protocol\RedirectPacket;
use kuoto\protocol\PlayerLoginPacket;
use kuoto\protocol\PlayerLogoutPacket;
use kuoto\protocol\TransferPacket;
use kuoto\protocol\TextPacket;
use kuoto\protocol\ClientDisconnectPacket;
use kuoto\protocol\ChangeDimensionPacket;

use kuoto\event\player\PlayerPreLoginEvent;
use kuoto\event\player\PlayerServerSelectEvent;
use kuoto\event\proxy\ProxyPingEvent;

/**
 * RakLibProxy - Full UDP proxy for MCPE clients
 * Implements the complete RakLib protocol including:
 * - Unconnected ping/pong
 * - OPEN_CONNECTION handshake (REQUEST_1/REPLY_1, REQUEST_2/REPLY_2)
 * - Connected phase (CLIENT_CONNECT, SERVER_HANDSHAKE, CLIENT_HANDSHAKE)
 * - Data packets with ACK/NACK
 * - Encapsulated packet decoding
 * - Game data forwarding to PocketMine backend
 */
class RakLibProxy
{
    // MAGIC must use chr() to avoid encoding issues with \x escapes on some PHP builds
    private static $MAGIC;
    private static function getMAGIC() {
        if (self::$MAGIC === null) {
            self::$MAGIC = chr(0x00) . chr(0xFF) . chr(0xFF) . chr(0x00) . chr(0xFE) . chr(0xFE) . chr(0xFE) . chr(0xFE) . chr(0xFD) . chr(0xFD) . chr(0xFD) . chr(0xFD) . chr(0x12) . chr(0x34) . chr(0x56) . chr(0x78);
        }
        return self::$MAGIC;
    }

    // Session states
    const STATE_UNCONNECTED = 0;
    const STATE_CONNECTING_1 = 1;
    const STATE_CONNECTING_2 = 2;
    const STATE_CONNECTED = 3;

    // Packet IDs
    const PKT_UNCONNECTED_PING = 0x01;
    const PKT_UNCONNECTED_PING_OPEN = 0x02;
    const PKT_OPEN_REQUEST_1 = 0x05;
    const PKT_OPEN_REPLY_1 = 0x06;
    const PKT_OPEN_REQUEST_2 = 0x07;
    const PKT_OPEN_REPLY_2 = 0x08;
    const PKT_CLIENT_CONNECT = 0x09;
    const PKT_SERVER_HANDSHAKE = 0x10;
    const PKT_CLIENT_HANDSHAKE = 0x13;
    const PKT_UNCONNECTED_PONG = 0x1C;
    const PKT_NACK = 0xA0;
    const PKT_ACK = 0xC0;

    // Reliability constants
    const UNRELIABLE = 0;
    const UNRELIABLE_SEQUENCED = 1;
    const RELIABLE = 2;
    const RELIABLE_ORDERED = 3;
    const RELIABLE_SEQUENCED = 4;

    /** @var \Socket */
    private $socket;
    /** @var ServerManager */
    private $manager;
    /** @var Logger */
    private $logger;
    /** @var string */
    private $bindIp;
    /** @var int */
    private $port;
    /** @var bool */
    private $running = false;
    /** @var int */
    private $serverGuid;
    /** @var int */
    private $protocolVersion = 84;
    /** @var string */
    private $versionName = '0.15.10';
    /** @var string */
    private $motdName = 'Kuoto Proxy';
    /** @var string */
    private $subMotd = 'A Minecraft PE Proxy';
    /** @var string */
    private $gamemode = 'Survival';
    /** @var int */
    private $maxPlayers = 20;
    /** @var array */
    private $sessions = array();
    /** @var int */
    private $nextSessionId = 0;

    public function __construct(ServerManager $manager, Logger $logger, $bindIp = '0.0.0.0', $port = 19132, $motd = 'Kuoto Proxy', $subMotd = 'A Minecraft PE Proxy', $gamemode = 'Survival', $protocol = 84, $version = '0.15.10', $maxPlayers = 20)
    {
        $this->manager = $manager;
        $this->logger = $logger;
        $this->bindIp = $bindIp;
        $this->port = $port;
        $this->motdName = $motd;
        $this->subMotd = $subMotd;
        $this->gamemode = $gamemode;
        $this->protocolVersion = $protocol;
        $this->versionName = $version;
        $this->maxPlayers = $maxPlayers;
        try {
            $this->serverGuid = random_int(1, 0x7FFFFFFF);
        } catch (\Exception $e) {
            $this->serverGuid = mt_rand(1, 0x7FFFFFFF);
        }
    }

    public function start()
    {
        $this->socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($this->socket === false) {
            $this->logger->error("Failed to create UDP socket");
            return false;
        }

        @socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!@socket_bind($this->socket, $this->bindIp, $this->port)) {
            $this->logger->error("Failed to bind to {$this->bindIp}:{$this->port}: " .
                socket_strerror(socket_last_error($this->socket)));
            @socket_close($this->socket);
            return false;
        }

        @socket_set_nonblock($this->socket);

        $this->running = true;
        $this->logger->info("Proxy RakLib escuchando en {$this->bindIp}:{$this->port}");
        $this->logger->info("Server GUID: {$this->serverGuid}");
        $this->logger->info("Protocol: {$this->protocolVersion} | Version: {$this->versionName}");
        return true;
    }

    public function tick()
    {
        if (!$this->running) {
            return;
        }

        for ($i = 0; $i < 32; $i++) {
            $address = '';
            $port = 0;
            $data = '';
            $bytes = @socket_recvfrom($this->socket, $data, 65535, 0, $address, $port);

            if ($bytes === false || $bytes === 0) {
                break;
            }

            // Isolate each datagram: a malformed/edge-case packet from one
            // client should never be able to abort processing of the other 31
            // datagrams in this batch (or, before SynapseServer::mainLoop()'s
            // own try/catch existed, the entire proxy process). See the
            // matching note in SynapseServer::mainLoop().
            try {
                $this->handlePacket($data, $address, $port);
            } catch (\Throwable $e) {
                $this->logger->critical(
                    "Uncaught " . get_class($e) . " handling packet from {$address}:{$port}: " . $e->getMessage()
                    . " at " . $e->getFile() . ":" . $e->getLine()
                );
                foreach (explode("\n", $e->getTraceAsString()) as $traceLine) {
                    $this->logger->critical("  " . $traceLine);
                }
            }
        }

        // Cleanup old sessions
        $now = microtime(true);
        foreach ($this->sessions as $key => $sess) {
            if ($now - $sess['lastActivity'] > 60) {
                $this->logger->info("Sesion expirada: {$key}");
                // Mirror handlePlayerDisconnect(): a session can go idle without the
                // client ever sending an explicit CLIENT_DISCONNECT (0x15) -- e.g. it
                // gave up locally and never told us. Without this, the player stays
                // registered forever and the session lingers as a "ghost" that can
                // later be mismatched by handleRedirectFromBackend().
                if (isset($sess['uuidHex'])) {
                    $this->manager->unregisterPlayer($sess['uuidHex']);
                }
                unset($this->sessions[$key]);
            }
        }

        // Expire stuck "waiting for backend" logins. A normal PlayerLogin -> backend
        // response round trip is near-instant; if it's been more than a few seconds,
        // the reply was most likely lost (or the backend never had a chance to send
        // it, e.g. the client vanished mid-login). Clearing the flag here stops that
        // session from being an eligible (and wrong) match in handleRedirectFromBackend()
        // for a completely different, currently-connecting player's login response.
        foreach ($this->sessions as $key => &$sess) {
            if (!empty($sess['waitingForBackend']) && isset($sess['backendWaitStarted'])
                && ($now - $sess['backendWaitStarted']) > 5) {
                //$this->logger->warning("Backend login response timed out for {$key}, no longer eligible for UUID mapping");
                $sess['waitingForBackend'] = false;
            }
        }
        unset($sess);

        // Send ACK queues
        foreach ($this->sessions as $key => &$sess) {
            if (!empty($sess['ackQueue'])) {
                $this->sendAck($sess, $sess['ackQueue']);
                $sess['ackQueue'] = [];
            }
        }
    }

    private function handlePacket($data, $address, $port)
    {
        $packetId = ord($data[0]);
        $key = $address . ':' . $port;

        switch ($packetId) {
            case self::PKT_UNCONNECTED_PING:
            case self::PKT_UNCONNECTED_PING_OPEN:
                $this->handlePing($data, $address, $port);
                break;
            case self::PKT_OPEN_REQUEST_1:
                $this->logger->debug("REQUEST_1 from {$address}:{$port} len=" . strlen($data) . " hex=" . bin2hex(substr($data, 0, 20)));
                $this->handleRequest1($data, $address, $port);
                break;
            case self::PKT_OPEN_REQUEST_2:
                $this->logger->debug("REQUEST_2 from {$address}:{$port} len=" . strlen($data) . " hex=" . bin2hex(substr($data, 0, 20)));
                $this->handleRequest2($data, $address, $port);
                break;
            case self::PKT_ACK:
                $this->handleAck($key, $data);
                break;
            case self::PKT_NACK:
                $this->handleNack($key, $data);
                break;
            default:
                // Handle data packets (0x80-0x8F) and connected packets
                if ($packetId >= 0x80 && $packetId <= 0x8F) {
                    if (isset($this->sessions[$key])) {
                        $this->handleDataPacket($key, $data);
                    }
                } else {
                    $this->logger->debug("Unknown packet 0x" . dechex($packetId) . " from {$address}:{$port} len=" . strlen($data));
                }
                break;
        }
    }

    // ========== UNCONNECTED PHASE ==========

    /**
     * Handle UNCONNECTED_PING - respond with MOTD
     */
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

    private function sendMessage(&$session, $message)
{
    $pk = new TextPacket();
    $pk->type = TextPacket::TYPE_RAW;
    $pk->message = $message;
    $pk->encode();

    $this->sendEncapsulated(
        $session,
        $pk->getBuffer(),
        self::RELIABLE_ORDERED
    );
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

    // ========== CONNECTED PHASE ==========

    /**
     * Handle data packets (0x80-0x8F)
     * These are RakLib data packets containing encapsulated data
     */
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
        $this->logger->debug("Encapsulated from {$session['address']}:{$session['port']} id=0x" . dechex($id) . " len=" . strlen($buffer) . " state=" . $session['state']);

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

    // ========== GAME DATA ==========

    /**
     * Forward game data from client to backend server
     * Handles the MCPE login flow: buffer packets until we find the real login,
     * then send PlayerLoginPacket to backend with the actual login data.
     *
     * IMPORTANT: the real SPP backend (Synapse::getPacket()/SynLibInterface::putPacket())
     * never decompresses zlib -- it only strips a single leading 0xFE byte and treats
     * whatever follows as ONE already-decoded packet. Every MCPE 0.15.x batch (0xFE +
     * zlib-compressed [length+data]*N) coming from the real client MUST therefore be
     * unwrapped into its individual packets here, with one RedirectPacket sent per
     * packet -- forwarding the raw compressed buffer as-is (as before) would leave the
     * backend unable to decode anything sent after the initial login (movement, chat,
     * inventory, chunk requests, etc. are almost always sent batched).
     */
    private function forwardGameData($key, &$session, $buffer)
    {
        if ($session['backendHash'] === null) {
            $packetId = ord($buffer[0]);

            // MCPE 0.15.x Genisys login packet is 0x01, sent unbatched
            if ($packetId === 0x01) {
                $session['loginPacket'] = $buffer;
                $this->logger->debug("Got raw login packet from {$key} (" . strlen($buffer) . "b)");
                $this->assignToServer($key, $session);
                return;
            }

            if (!isset($session['pendingBuffers'])) {
                $session['pendingBuffers'] = [];
            }

            if ($packetId === 0xfe) {
                // Unwrap the batch into individual packets (see unwrapBatch() doc)
                // and look for the login packet (0x01) among them.
                $packets = $this->unwrapBatch($buffer);
                $loginData = null;
                foreach ($packets as $pkData) {
                    if ($loginData === null && strlen($pkData) > 0 && ord($pkData[0]) === 0x01) {
                        $loginData = $pkData;
                        continue;
                    }
                    // Queue every other unwrapped packet as an individual pending
                    // buffer -- never the raw batch -- so assignToServer() can later
                    // forward them one RedirectPacket at a time, matching what the
                    // backend expects.
                    $session['pendingBuffers'][] = $pkData;
                }

                if ($loginData !== null) {
                    $session['loginPacket'] = $loginData;
                    $this->logger->debug("Extracted login from batch for {$key} (" . strlen($loginData) . "b)");
                    $this->assignToServer($key, $session);
                }
                return;
            }

            // Any other pre-login packet (e.g. 0x00 keepalive) is already a single
            // unbatched packet - buffer it as-is.
            $session['pendingBuffers'][] = $buffer;
            $this->logger->debug("Buffering pre-login packet 0x" . dechex($packetId) . " from {$key} (" . strlen($buffer) . "b)");
            return;
        }

        $server = $this->manager->getServer($session['backendHash']);
        if ($server === null || !$server->isAuthenticated()) {
            return;
        }

        // Unwrap batches here too: post-login client traffic is batched just as
        // often as the login packet was, and the backend can't decompress it either.
        $packets = (strlen($buffer) > 0 && ord($buffer[0]) === 0xfe) ? $this->unwrapBatch($buffer) : [$buffer];

        foreach ($packets as $pkData) {
            $redirect = new RedirectPacket();
            $redirect->uuid = $session['uuid'];
            $redirect->direct = false;
            $redirect->mcpeBuffer = $pkData;
            $server->sendPacket($redirect);
        }
    }

    /**
     * Unwrap a MCPE 0.15.x batch packet (0xFE + zlib-compressed [length(4)+data]*N)
     * into its individual raw packets. Returns a list of raw packet byte strings,
     * each starting with its own real packet ID (never 0xFE).
     *
     * If the buffer isn't a batch, or decompression/parsing fails entirely, returns
     * the original buffer unchanged as the sole element -- the caller (or the
     * backend) will have to deal with it as-is, but we don't silently drop data.
     */
    private function unwrapBatch($buffer)
    {
        if (strlen($buffer) < 2 || ord($buffer[0]) !== 0xfe) {
            return [$buffer];
        }

        $compressed = substr($buffer, 1);

        $this->logger->debug("Batch hex (first 20): " . bin2hex(substr($compressed, 0, 20)));

        // Check if maybe no compression at all - try parsing as raw payload
        // MCPE batch without compression: [4-byte count] + [4-byte len + pk] * N
        $pkCount = unpack('N', substr($compressed, 0, 4))[1];
        if ($pkCount > 0 && $pkCount < 100) {
            $firstLen = unpack('N', substr($compressed, 4, 4))[1];
            if ($firstLen > 0 && $firstLen < strlen($compressed) - 8) {
                $parsed = $this->parseBatchPayload(substr($compressed, 4), 'no_count');
                if (!empty($parsed)) {
                    $this->logger->debug("Batch appears uncompressed: count={$pkCount} firstLen={$firstLen}");
                    return $parsed;
                }
            }
        }

        $decompressed = false;
        $method = 'none';

        // MCPE 0.15.x batch: 0xFE + count(1) + length(4) + zlib_data
        // ZLIB header (78 da) is at offset 6 of buffer
        $offsets = [6, 5, 1]; // Try offset 6 first (where 78da zlib header is)
        foreach ($offsets as $off) {
            if ($off >= strlen($buffer)) continue;
            $data = substr($buffer, $off);

            $result = @zlib_decode($data); // raw DEFLATE - what PocketMine uses
            if ($result !== false && strlen($result) > 0) {
                $decompressed = $result;
                $method = 'zlib_decode@' . $off;
                break;
            }

            $result = @gzuncompress($data); // ZLIB format
            if ($result !== false && strlen($result) > 0) {
                $decompressed = $result;
                $method = 'gzuncompress@' . $off;
                break;
            }

            $result = @gzdecode($data); // GZIP
            if ($result !== false && strlen($result) > 0) {
                $decompressed = $result;
                $method = 'gzdecode@' . $off;
                break;
            }

            $result = @gzinflate($data);
            if ($result !== false && strlen($result) > 0) {
                $decompressed = $result;
                $method = 'gzinflate@' . $off;
                break;
            }
        }

        if ($decompressed === false || strlen($decompressed) === 0) {
            $this->logger->debug("All batch decompression methods failed for len=" . strlen($buffer) . " - forwarding raw buffer unchanged");
            return [$buffer];
        }

        $this->logger->debug("Batch method={$method} decompressed: " . strlen($compressed) . " -> " . strlen($decompressed) . " bytes");
        $this->logger->debug("Batch decompressed hex (first 32): " . bin2hex(substr($decompressed, 0, 32)));

        foreach (['with_count' => 4, 'no_count' => 0] as $fname => $startOffset) {
            $parsed = $this->parseBatchPayload(substr($decompressed, $startOffset), $fname);
            if (!empty($parsed)) {
                return $parsed;
            }
        }

        // Last resort: the decompressed data might just be a single raw packet
        // with no [length+data] framing at all.
        if (strlen($decompressed) > 0) {
            $this->logger->debug("No [length+data] framing found in batch, treating decompressed data as a single packet");
            return [$decompressed];
        }

        return [$buffer];
    }

    /**
     * Parse a [length(4) + data] * N payload into individual packets.
     * @return string[] empty array if parsing didn't yield anything usable
     */
    private function parseBatchPayload($decompressed, $label)
    {
        $offset = 0;
        $found = [];
        while ($offset < strlen($decompressed)) {
            if ($offset + 4 > strlen($decompressed)) break;
            $pkLen = unpack('N', substr($decompressed, $offset, 4))[1];
            $offset += 4;
            if ($pkLen <= 0 || $pkLen > 65535 || $offset + $pkLen > strlen($decompressed)) {
                break;
            }
            $pkData = substr($decompressed, $offset, $pkLen);
            $offset += $pkLen;
            $found[] = $pkData;
        }

        if (!empty($found)) {
            $names = [];
            foreach ($found as $pkData) {
                $names[] = '0x' . dechex(ord($pkData[0])) . '(' . strlen($pkData) . 'b)';
            }
            $this->logger->debug("Batch {$label}: " . implode(', ', $names));
        }

        return $found;
    }

    public function transferPlayer($uuid, $targetHash)
{
    $uuidHex = strtolower(str_replace('-', '', bin2hex($uuid)));

    $target = $this->manager->getServer($targetHash);

    if ($target === null || !$target->isAuthenticated()) {
        $this->logger->warning(
            "No se puede trasladar {$uuidHex}: destino {$targetHash} no disponible"
        );

        return false;
    }

    foreach ($this->sessions as $key => &$session) {
        if ($session['state'] !== self::STATE_CONNECTED) {
            continue;
        }

        $match = false;

        if (isset($session['uuidHex'])
            && strtolower($session['uuidHex']) === $uuidHex) {

            $match = true;
        }

        if (!$match
            && isset($session['backendUuidHex'])
            && strtolower($session['backendUuidHex']) === $uuidHex) {

            $match = true;
        }

        if (!$match) {
            continue;
        }

        $internalUuidHex = $session['uuidHex'];

        $oldHash = isset($session['backendHash'])
            ? $session['backendHash']
            : null;

        if ($oldHash === $targetHash) {
            $this->logger->warning(
                "El jugador {$internalUuidHex} ya esta conectado a {$targetHash}"
            );

            return false;
        }

        $currentDimension = isset($session['dimension'])
            ? $session['dimension']
            : ChangeDimensionPacket::DIMENSION_NORMAL;

        $fakeDimension = $currentDimension === ChangeDimensionPacket::DIMENSION_NETHER
            ? ChangeDimensionPacket::DIMENSION_NORMAL
            : ChangeDimensionPacket::DIMENSION_NETHER;

        $this->sendChangeDimension(
            $session,
            $fakeDimension
        );

        $session['dimension'] = $fakeDimension;

        if ($oldHash !== null) {
            $oldServer = $this->manager->getServer($oldHash);

            if ($oldServer !== null) {
                $logout = new PlayerLogoutPacket();
                $logout->uuid = $session['uuid'];
                $logout->reason = 'transfer';

                $oldServer->sendPacket($logout);

                $this->logger->info(
                    "PlayerLogout enviado a {$oldHash} para {$internalUuidHex}"
                );
            }
        }

        $moved = $this->manager->movePlayer(
            $internalUuidHex,
            $targetHash
        );

        if (!$moved) {
            $this->manager->registerPlayer(
                $internalUuidHex,
                $targetHash,
                $session['address'],
                $session['port']
            );
        }

        $session['backendHash'] = $targetHash;
        $session['waitingForBackend'] = true;
        $session['backendWaitStarted'] = microtime(true);

        $loginPacket = isset($session['loginPacket'])
            ? $session['loginPacket']
            : '';

        $login = new PlayerLoginPacket();
        $login->uuid = $session['uuid'];
        $login->address = $session['address'];
        $login->port = $session['port'];
        $login->isFirstTime = false;
        $login->cachedLoginPacket = $loginPacket;

        $target->sendPacket($login);

        $this->logger->info(
            "PlayerLogin reenviado a {$targetHash} para {$internalUuidHex}"
        );

        $transfer = new TransferPacket();
        $transfer->uuid = $session['uuid'];
        $transfer->clientHash = $targetHash;

        $target->sendPacket($transfer);

        $this->logger->info(
            "TransferPacket enviado a {$targetHash} para {$internalUuidHex}"
        );

        if (isset($session['pendingBuffers'])
            && !empty($session['pendingBuffers'])) {

            foreach ($session['pendingBuffers'] as $buf) {
                $redirect = new RedirectPacket();
                $redirect->uuid = $session['uuid'];
                $redirect->direct = false;
                $redirect->mcpeBuffer = $buf;

                $target->sendPacket($redirect);
            }

            $session['pendingBuffers'] = array();
        }

        $this->logger->info(
            "Ruta de {$internalUuidHex} cambiada: "
            . $oldHash . " -> " . $targetHash
        );

        return true;
    }

    $resolved = $this->manager->resolvePlayerUuid($uuidHex);

    if ($resolved !== null) {
        foreach ($this->sessions as $key => &$session) {
            if (!isset($session['uuidHex'])) {
                continue;
            }

            if (strtolower($session['uuidHex']) !== $resolved) {
                continue;
            }

            if ($session['state'] !== self::STATE_CONNECTED) {
                continue;
            }

            return $this->transferPlayer(
                $session['uuid'],
                $targetHash
            );
        }
    }

    $this->logger->warning(
        "No se encontro sesion RakNet para UUID {$uuidHex}"
    );

    return false;
}

    /**
     * Busca la sesion cuyo UUID "real" de cuenta (el que decodifica el
     * backend del login chain, guardado en backendUuidHex una vez llega el
     * primer RedirectPacket) coincide con el que se pasa, y devuelve el
     * UUID interno del proxy -- el que se usa en todos lados (ServerManager,
     * /kick, sesiones RakNet). Los paquetes que vienen del backend (como
     * FastPlayerListPacket) solo traen el UUID real de cuenta, nunca el
     * interno del proxy, asi que hace falta esta traduccion para poder
     * asociar un nombre de jugador a la sesion correcta.
     *
     * @param string $backendUuidHex
     * @return string|null
     */
    public function getSessionUuidHexByBackendUuid($backendUuidHex)
    {
        foreach ($this->sessions as $session) {
            if (isset($session['backendUuidHex']) && $session['backendUuidHex'] === $backendUuidHex) {
                return $session['uuidHex'];
            }
        }

        return null;
    }

    /**
     * Assign player to a backend server
     */
    private function assignToServer($key, &$session)
    {
        if (isset($session['backendHash']) && $session['backendHash'] !== null) {
            return; // Already assigned
        }

        $servers = $this->manager->getAuthenticatedServers();
        if (empty($servers)) {
            $this->logger->warning("No PocketMine servers available for {$session['address']}:{$session['port']}");
            $this->logger->warning(
    "No PocketMine servers available for {$session['address']}:{$session['port']}"
);
$this->disconnectSession($key, $session);

return;
        }

        // Ultimo punto en el que se puede rechazar a un jugador (whitelist,
        // baneos, mantenimiento...) antes de meterlo en un backend.
        $preLogin = new PlayerPreLoginEvent($session['uuid'], $session['address'], $session['port']);
        $preLogin->call();
        if ($preLogin->isCancelled()) {
            $this->logger->info(
                "Login de {$session['address']}:{$session['port']} cancelado por un listener: "
                . $preLogin->getKickMessage()
            );
            $this->disconnectSession($key, $session);
            return;
        }

        $bestServer = $this->selectServer($servers);

        if ($bestServer !== null) {
            // Un listener puede sobreescribir la eleccion del balanceador
            // (lobbies, colas, VIPs...) o cancelar la asignacion.
            $selectEvent = new PlayerServerSelectEvent(
                $session['uuid'],
                $session['address'],
                $session['port'],
                $bestServer,
                $servers
            );
            $selectEvent->call();
            if ($selectEvent->isCancelled()) {
                $this->logger->info("Asignacion de servidor cancelada para {$session['address']}:{$session['port']}");
                return;
            }
            $bestServer = $selectEvent->getTargetServer();

            $session['backendHash'] = $bestServer->getHash();
            $this->logger->info("Jugador {$session['address']}:{$session['port']} -> {$bestServer->getHash()}");

            // Register the player with the manager so getPlayerCount()/getPlayerServer()
            // reflect reality. ClientConnection::handlePlayerLogin() (the only other
            // caller of registerPlayer()) only fires when the proxy RECEIVES a
            // PlayerLoginPacket from a backend, but here the proxy is the one SENDING
            // it to the backend -- so without this call the player is never registered
            // and the player counter stays stuck at 0 forever.
            $this->manager->registerPlayer($session['uuidHex'], $bestServer->getHash(), $session['address'], $session['port']);

            // Send PlayerLoginPacket to backend with the login packet
            $loginPacket = isset($session['loginPacket']) && $session['loginPacket'] !== null ? $session['loginPacket'] : '';

            $pk = new PlayerLoginPacket();
            $pk->uuid = $session['uuid'];
            $pk->address = $session['address'];
            $pk->port = $session['port'];
            $pk->isFirstTime = true;
            $pk->cachedLoginPacket = $loginPacket;

            $bestServer->sendPacket($pk);
            $session['waitingForBackend'] = true;
            // Stamped so handleRedirectFromBackend() can tell a genuinely-pending
            // login apart from a session that has been "waiting" forever because
            // its backend reply was lost (see tick()'s expiry pass below) -- without
            // this, a stuck session can silently steal the UUID mapping meant for a
            // different, currently-connecting player.
            $session['backendWaitStarted'] = microtime(true);
            $this->logger->debug("PlayerLogin enviado para {$session['address']}:{$session['port']} (loginPacket=" . strlen($loginPacket) . "b)");

            // Now forward any pending buffered packets
            if (isset($session['pendingBuffers']) && !empty($session['pendingBuffers'])) {
                $this->logger->debug("Forwarding " . count($session['pendingBuffers']) . " buffered packets for {$key}");
                foreach ($session['pendingBuffers'] as $buf) {
                    $redirect = new RedirectPacket();
                    $redirect->uuid = $session['uuid'];
                    $redirect->direct = false;
                    $redirect->mcpeBuffer = $buf;
                    $bestServer->sendPacket($redirect);
                }
                $session['pendingBuffers'] = [];
            }
        }
    }

    /**
     * Balanceo por defecto: el backend autenticado con menos jugadores.
     *
     * Extraido de assignToServer() para que la politica de balanceo sea una
     * sola cosa facil de cambiar (o de sustituir desde un listener con
     * PlayerServerSelectEvent).
     *
     * @param ClientConnection[] $servers
     * @return ClientConnection|null
     */
    private function selectServer(array $servers)
    {
        $best = null;
        $minPlayers = PHP_INT_MAX;

        foreach ($servers as $server) {
            $info = $server->getInfo();
            if ($info['playerCount'] < $minPlayers) {
                $minPlayers = $info['playerCount'];
                $best = $server;
            }
        }

        return $best;
    }


    /**
     * Manda un ChangeDimensionPacket directo al cliente para forzar la
     * pantalla de carga ("Generando terreno"). Se usa como truco visual al
     * trasladar un jugador entre backends: el cliente tapa el mundo viejo
     * con la pantalla de carga mientras el nuevo servidor termina su login,
     * en vez de dejar ver el mundo congelado o el vacio.
     */
    private function sendChangeDimension(&$session, $dimension, $x = 0.0, $y = 0.0, $z = 0.0)
{
    $pk = new ChangeDimensionPacket();
    $pk->dimension = $dimension;
    $pk->x = $x;
    $pk->y = $y;
    $pk->z = $z;
    $pk->encode();

    $this->sendEncapsulated(
        $session,
        $pk->getBuffer(),
        self::RELIABLE_ORDERED
    );
}

    private function sendDisconnect(&$session, $message)
{
    $pk = new ClientDisconnectPacket();
    $pk->message = $message;
    $pk->encode();

    $this->logger->info(
        "Enviando DisconnectPacket a {$session['address']}:{$session['port']}: {$message}"
    );

    $this->logger->info(
        "Disconnect HEX: " . bin2hex($pk->getBuffer())
    );

    $this->sendEncapsulated(
        $session,
        $pk->getBuffer(),
        self::RELIABLE_ORDERED
    );
}
    /**
     * Cierra una sesion de jugador desde el lado del proxy (sin backend
     * asignado todavia), avisando al cliente.
     *
     * @param string $key
     * @param array $session
     */
    private function disconnectSession($key, &$session)
{
    if ($session['state'] === self::STATE_CONNECTED) {
        $this->sendMessage(
            $session,
            '§9§lKuoto§f |§7 Proxy §r§7: §fHas sido desconectado del proxy.'
        );

        $this->sendEncapsulated(
            $session,
            chr(0x15) . chr(0x00),
            self::RELIABLE_ORDERED
        );
    }

    $this->manager->unregisterPlayer($session['uuidHex']);

    unset($this->sessions[$key]);
    
}

    /**
     * Handle player disconnect
     */
    private function handlePlayerDisconnect($key, &$session)
    {
        if ($session['backendHash'] !== null) {
            $server = $this->manager->getServer($session['backendHash']);
            if ($server !== null) {
                $pk = new PlayerLogoutPacket();
                $pk->uuid = $session['uuid'];
                $server->sendPacket($pk);
            }
        }
        // Mirror the registerPlayer() call in assignToServer() -- otherwise the
        // manager's player map (and therefore getPlayerCount()/getPlayerServer())
        // leaks an entry every time a player disconnects.
        $this->manager->unregisterPlayer($session['uuidHex']);
        unset($this->sessions[$key]);
    }

    // ========== SEND HELPERS ==========

    /**
     * Send an encapsulated packet to the client
     */
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
        $acked = [];
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
                    $acked[] = $j;
                    unset($session['recoveryQueue'][$j]);
                }
            } else {
                // Single: triad(3)
                if ($offset + 3 > strlen($data)) break;
                $seq = $this->readLTriad($data, $offset);
                $offset += 3;
                $acked[] = $seq;
                unset($session['recoveryQueue'][$seq]);
            }
        }
        // Uncomment for debugging ACK issues:
        // $this->logger->info("ACK from {$session['address']}:{$session['port']} count=" . count($acked));
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
    public function handleRedirectFromBackend($uuid, $data, $backendHash = null)
    {
        $uuidHex = bin2hex($uuid);

        // Find the session for this UUID
        $session = null;
        $sessionKey = null;

        // Try direct UUID match first -- either the original proxy-assigned uuid
        // (used before the backend has replied even once), or the backend's own
        // "real" uuid once we've learned it below.
        foreach ($this->sessions as $key => &$sess) {
            if ((isset($sess['uuidHex']) && $sess['uuidHex'] === $uuidHex)
                || (isset($sess['backendUuidHex']) && $sess['backendUuidHex'] === $uuidHex)) {
                $session = &$sess;
                $sessionKey = $key;
                break;
            }
        }

        // No direct match -- this is expected for the *first* redirect after a
        // login: the proxy sent its own temporary per-connection UUID in
        // PlayerLoginPacket, and the backend replies using the account's real
        // UUID (decoded from the login chain) instead. We have to map the two
        // together here.
        //
        // IMPORTANT: RedirectPacket carries no field that ties it back to a
        // specific session (no address/port, no echo of the original UUID), so
        // the only thing we can go on is "which session(s) are still genuinely
        // waiting on a backend reply". Collect ALL such candidates rather than
        // grabbing the first one blindly -- a stale/ghost session whose reply
        // was lost (client vanished without a clean disconnect) must never be
        // able to steal the mapping meant for the player who is actually
        // connecting right now, or that real player's game data gets sent to a
        // dead socket and they silently time out a moment after joining.
        if ($session === null) {
            $candidates = [];
            foreach ($this->sessions as $key => &$sess) {
                if ($sess['state'] === self::STATE_CONNECTED && !empty($sess['waitingForBackend'])) {
                    // Prefer sessions assigned to the same backend server this
                    // redirect actually came from, when we know it.
                    if ($backendHash !== null && isset($sess['backendHash']) && $sess['backendHash'] !== $backendHash) {
                        continue;
                    }
                    $candidates[$key] = &$sess;
                }
            }
            unset($sess);

            if (count($candidates) > 1) {
                $this->logger->warning("Ambiguous backend UUID mapping for {$uuidHex}: " . count($candidates) . " sessions waiting simultaneously (" . implode(', ', array_keys($candidates)) . ") -- picking the oldest pending login");
            }

            // Break ties (or just pick the only candidate) by earliest
            // backendWaitStarted, i.e. whichever login was actually sent first.
            $bestKey = null;
            $bestTime = INF;
            foreach ($candidates as $key => &$sess) {
                $started = isset($sess['backendWaitStarted']) ? $sess['backendWaitStarted'] : 0;
                if ($started < $bestTime) {
                    $bestTime = $started;
                    $bestKey = $key;
                }
            }
            unset($sess);

            if ($bestKey !== null) {
                $sess = &$this->sessions[$bestKey];
                // DO NOT overwrite $sess['uuid']/uuidHex here. That uuid is what
                // the proxy originally sent the backend in PlayerLoginPacket, and
                // the backend's internal player map (Synapse::$players in the real
                // SynapseAPI-derived backend code) is keyed by that exact value
                // forever -- it's never re-indexed when the backend later learns
                // the player's real account uuid from the login chain. Every
                // RedirectPacket the proxy sends to the backend for this player
                // (movement, chat, everything post-login) MUST keep using the
                // original uuid, or the backend's isset($this->players[$uuid])
                // check silently fails and drops the packet -- which is exactly
                // what caused the ~15s-after-join kick: the client got no more
                // world/keepalive responses and timed out on its own.
                //
                // We only need the backend's uuid to recognize *its own* outgoing
                // RedirectPackets for this player from now on, so stash it under
                // a separate key instead of replacing the routing uuid.
                $sess['backendUuid'] = $uuid;
                $sess['backendUuidHex'] = $uuidHex;
                $sess['waitingForBackend'] = false;
                // Sin esto, ServerManager::resolvePlayerUuid() nunca aprende la
                // relacion uuid-real <-> uuid-interno, y cualquier comando que
                // reciba el UUID real de cuenta (kick, etc.) falla con
                // "jugador no encontrado" aunque el jugador este conectado.
                $this->manager->setBackendUuid($sess['uuidHex'], $uuidHex);
                $this->logger->info("UUID mapeado desde el backend: {$sess['uuidHex']} <-> {$uuidHex}");
                $session = &$sess;
                $sessionKey = $bestKey;
            }
        }

        if ($session === null || $session['state'] !== self::STATE_CONNECTED) {
            $this->logger->debug("No session found for UUID {$uuidHex}");
            return false;
        }

        // Check if data is already a batch packet (starts with 0xFE)
        if (ord($data[0]) === 0xfe && strlen($data) > 10) {
            $this->sendEncapsulated($session, $data, self::RELIABLE_ORDERED);
            return true;
        }

        // Backend sends raw MCPE packets.
        // Genisys batch format: 0xFE + 0x06 + compressed_length(4) + gzcompress(payload)
        // Payload: int(length) + packet_data
        $payload = pack("N", strlen($data)) . $data;
        $compressed = @gzcompress($payload);
        if ($compressed !== false && strlen($compressed) > 0) {
            $batch = chr(0xfe) . chr(0x06) . pack("N", strlen($compressed)) . $compressed;
            $this->sendEncapsulated($session, $batch, self::RELIABLE_ORDERED);
        } else {
            $this->sendEncapsulated($session, $data, self::RELIABLE_ORDERED);
        }
        return true;
    }

    /**
     * Decompress a MCPE batch packet and return individual packets
     * Batch format: 0xFE + zlib_compressed([length(4) + data] * N)
     */
    private function decompressBatch($data)
    {
        $packets = [];
        if (strlen($data) < 2 || ord($data[0]) !== 0xfe) {
            return $packets;
        }

        // Try zlib_decode (raw DEFLATE) first - what PocketMine uses
        $decompressed = @zlib_decode(substr($data, 1));
        if ($decompressed === false || strlen($decompressed) === 0) {
            // Try gzuncompress (ZLIB format)
            $decompressed = @gzuncompress(substr($data, 1));
        }
        if ($decompressed === false || strlen($decompressed) === 0) {
            return $packets;
        }

        // Parse: [length(4) + data] * N
        $offset = 0;
        while ($offset < strlen($decompressed)) {
            if ($offset + 4 > strlen($decompressed)) break;
            $pkLen = unpack('N', substr($decompressed, $offset, 4))[1];
            $offset += 4;
            if ($pkLen <= 0 || $pkLen > 65535 || $offset + $pkLen > strlen($decompressed)) break;
            $packets[] = substr($decompressed, $offset, $pkLen);
            $offset += $pkLen;
        }

        return $packets;
    }

    /**
     * Handle player logout from backend
     */
    public function handlePlayerLogout($uuid, $reason = '')
{
    $uuidHex = strtolower(str_replace('-', '', bin2hex($uuid)));

    foreach ($this->sessions as $key => &$session) {
        $match = false;

        if (isset($session['uuidHex'])
            && strtolower($session['uuidHex']) === $uuidHex) {

            $match = true;
        }

        if (!$match
            && isset($session['backendUuidHex'])
            && strtolower($session['backendUuidHex']) === $uuidHex) {

            $match = true;
        }

        if (!$match) {
            continue;
        }

        if ($session['state'] === self::STATE_CONNECTED) {
            $message = $reason !== 'xd'
                ? $reason
                : 'Has sido expulsado del servidor';

            $this->sendDisconnect(
                $session,
                $message
            );
        }

        $internalUuidHex = $session['uuidHex'];

        $this->manager->unregisterPlayer(
            $internalUuidHex
        );

        $address = isset($session['address'])
            ? $session['address']
            : 'unknown';

        $port = isset($session['port'])
            ? $session['port']
            : 'unknown';

        unset($this->sessions[$key]);

        $this->logger->info(
            "Sesion eliminada para {$address}:{$port}"
            . ($reason !== ''
                ? " (motivo: {$reason})"
                : '')
        );

        return true;
    }

    $resolved = $this->manager->resolvePlayerUuid(
        $uuidHex
    );

    if ($resolved !== null) {
        $this->manager->unregisterPlayer(
            $resolved
        );
    }

    return false;
}

    public function shutdown()
    {
        $this->running = false;
        if ($this->socket !== null) {
            @socket_close($this->socket);
            $this->socket = null;
        }
    }

    public function packLong($value)
    {
        return Binary::writeLong($value);
    }

    public function unpackLong($data)
    {
        return Binary::readLong($data);
    }

    private function readLTriad($data, $offset)
    {
        return Binary::readLTriad($data, $offset);
    }

    private function writeLTriad($value)
    {
        return Binary::writeLTriad($value);
    }

    private function readShort($data, $offset)
    {
        return Binary::readShort($data, $offset);
    }

    private function readInt($data, $offset)
    {
        return Binary::readInt($data, $offset);
    }

    public function isRunning()
    {
        return $this->running;
    }
}