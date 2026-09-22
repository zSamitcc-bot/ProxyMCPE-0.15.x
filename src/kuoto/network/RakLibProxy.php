<?php

namespace kuoto\network;

use kuoto\server\ServerManager;
use kuoto\utils\Logger;
use kuoto\raklib\Binary;

use kuoto\network\raklibproxy\UnconnectedTrait;
use kuoto\network\raklibproxy\DataPacketTrait;
use kuoto\network\raklibproxy\GameDataTrait;
use kuoto\network\raklibproxy\ServerAssignmentTrait;
use kuoto\network\raklibproxy\SendTrait;
use kuoto\network\raklibproxy\BackendRedirectTrait;

/**
 * RakLibProxy - Full UDP proxy for MCPE clients
 * Implements the complete RakLib protocol including:
 * - Unconnected ping/pong
 * - OPEN_CONNECTION handshake (REQUEST_1/REPLY_1, REQUEST_2/REPLY_2)
 * - Connected phase (CLIENT_CONNECT, SERVER_HANDSHAKE, CLIENT_HANDSHAKE)
 * - Data packets with ACK/NACK
 * - Encapsulated packet decoding
 * - Game data forwarding to PocketMine backend
 *
 * La logica esta dividida en varios traits ayudantes bajo
 * kuoto\network\raklibproxy\ (uno por fase del protocolo) para que
 * ningun archivo se vuelva demasiado grande. Los traits comparten las
 * mismas propiedades y el mismo $this que esta clase, asi que el
 * comportamiento es identico a tenerlo todo en un solo archivo.
 */
class RakLibProxy
{
    use UnconnectedTrait;
    use DataPacketTrait;
    use GameDataTrait;
    use ServerAssignmentTrait;
    use SendTrait;
    use BackendRedirectTrait;

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

    // Limites de memoria por sesion (ver handleDataPacket() y sendEncapsulated())
    const RECEIVED_WINDOW_MAX = 4096;
    const RECEIVED_WINDOW_KEEP = 2048;
    const RECOVERY_QUEUE_MAX = 2048;

    // Deteccion de sesiones muertas (ver tick()). Antes era puramente pasiva
    // (solo se refrescaba lastActivity si llegaba algo del cliente), lo que
    // expulsaba por "timeout" a jugadores AFK reales cuyo cliente no generaba
    // trafico propio dentro de la ventana -- independientemente de a que
    // backend estuvieran conectados, porque este proxy es el unico front-end
    // UDP compartido por todos los servers. Ahora, ademas de dar mas margen
    // pasivo, el proxy manda un ping activo (ver maybeSendKeepalive()) que
    // fuerza un ACK a nivel de RakNet en cualquier cliente que siga vivo,
    // sin depender de que el propio cliente decida hablar primero.
    const SESSION_IDLE_TIMEOUT = 300;
    const KEEPALIVE_AFTER_IDLE = 20;
    const KEEPALIVE_MIN_INTERVAL = 10;

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
        foreach ($this->sessions as $key => &$sess) {
            if ($now - $sess['lastActivity'] > self::SESSION_IDLE_TIMEOUT) {
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
                continue;
            }

            // Keepalive activo: si el cliente lleva un rato sin generar trafico
            // propio (tipico de un jugador AFK real, o de un cliente que reduce
            // su propio ping mientras esta en background), no esperamos a que
            // hable el solo -- le mandamos un paquete RELIABLE. Cualquier stack
            // RakNet vivo esta obligado a ACKearlo a nivel de protocolo aunque
            // el contenido no signifique nada para el juego, y ese ACK entrante
            // refresca lastActivity via handleAck(). Asi solo llegan al timeout
            // de arriba las sesiones realmente muertas (socket cerrado, cliente
            // colgado, red caida), no los jugadores simplemente inactivos.
            $this->maybeSendKeepalive($sess, $now);
        }
        unset($sess);

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