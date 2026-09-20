<?php

namespace kuoto\network;

use kuoto\server\SynapseServer;
use kuoto\server\ServerManager;
use kuoto\utils\Logger;

use kuoto\protocol\Info;
use kuoto\protocol\DataPacket;
use kuoto\protocol\PacketPool;
use kuoto\protocol\ConnectPacket;
use kuoto\protocol\DisconnectPacket;
use kuoto\protocol\HeartbeatPacket;
use kuoto\protocol\InformationPacket;
use kuoto\protocol\RedirectPacket;
use kuoto\protocol\PlayerLoginPacket;
use kuoto\protocol\PlayerLogoutPacket;
use kuoto\protocol\TransferPacket;
use kuoto\protocol\BroadcastPacket;
use kuoto\protocol\FastPlayerListPacket;

use kuoto\event\network\DataPacketReceiveEvent;
use kuoto\event\network\DataPacketSendEvent;
use kuoto\event\player\PlayerLoginEvent;
use kuoto\event\player\PlayerLogoutEvent;
use kuoto\event\player\PlayerTransferEvent;
use kuoto\event\server\ServerAuthenticatedEvent;
use kuoto\event\server\ServerConnectEvent;
use kuoto\event\server\ServerDisconnectEvent;
use kuoto\event\server\ServerHeartbeatEvent;

class ClientConnection
{
    /** @var \Socket */
    private $socket;
    /** @var string */
    private $ip;
    /** @var int */
    private $port;
    /** @var Logger */
    private $logger;
    /** @var SynapseServer */
    private $server;
    /** @var ServerManager */
    private $manager;

    /** @var string */
    private $receiveBuffer = '';
    /** @var string */
    private $sendBuffer = '';
    /** @var bool */
    private $connected = true;
    /** @var bool */
    private $authenticated = false;
    /** @var float */
    private $lastHeartbeat = 0;
    /** @var string */
    private $hash;

    /** @var string */
    private $serverName = 'unknown';
    /** @var int */
    private $maxPlayers = 0;
    /** @var bool */
    private $isMainServer = false;
    /** @var string */
    private $description = '';

    /** @var float */
    private $tps = 20.0;
    /** @var float */
    private $load = 0.0;
    /** @var int */
    private $upTime = 0;
    /** @var float */
    private $connectedAt = 0;

    /** @var array uuidHex => serverHash */
    private $players = array();

    /**
     * Mapa id de paquete => metodo que lo procesa.
     * @var string[]
     */
    private static $HANDLERS = array(
        Info::CONNECT_PACKET           => 'handleConnect',
        Info::HEARTBEAT_PACKET         => 'handleHeartbeat',
        Info::DISCONNECT_PACKET        => 'handleDisconnect',
        Info::PLAYER_LOGIN_PACKET      => 'handlePlayerLogin',
        Info::PLAYER_LOGOUT_PACKET     => 'handlePlayerLogout',
        Info::REDIRECT_PACKET          => 'handleRedirect',
        Info::TRANSFER_PACKET          => 'handleTransfer',
        Info::BROADCAST_PACKET         => 'handleBroadcast',
        Info::FAST_PLAYER_LIST_PACKET  => 'handleFastPlayerList',
    );

    /**
    * @param \Socket $socket
     * @param SynapseServer $server
     * @param ServerManager $manager
     * @param Logger $logger
     */
    public function __construct($socket, SynapseServer $server, ServerManager $manager, Logger $logger)
    {
        $this->socket = $socket;
        $this->server = $server;
        $this->manager = $manager;
        $this->logger = $logger;
        $this->connectedAt = microtime(true);

        @socket_getpeername($socket, $address, $port);
        $this->ip = isset($address) ? $address : 'unknown';
        $this->port = isset($port) ? $port : 0;
        $this->hash = $this->ip . ':' . $this->port;

        $this->lastHeartbeat = microtime(true);
    }

    /** @return string */
    public function getHash()        { return $this->hash; }
    /** @return string */
    public function getIp()          { return $this->ip; }
    /** @return int */
    public function getPort()        { return $this->port; }
    /** @return bool */
    public function isAuthenticated(){ return $this->authenticated; }
    /** @return bool */
    public function isConnected()    { return $this->connected; }
    /** @return string */
    public function getServerName()  { return $this->serverName; }
    /** @return resource */
    public function getSocket()      { return $this->socket; }

    /**
     * @return array
     */
    public function getInfo()
    {
        // $this->players nunca se llena (handlePlayerLogin solo dispara si
        // el proxy RECIBE un PlayerLoginPacket del backend, y aqui siempre
        // es al reves: el proxy lo manda). El ServerManager si tiene el
        // mapa real, poblado desde RakLibProxy::assignToServer()/transferPlayer().
        $playersOnServer = $this->manager->getPlayersOnServer($this->hash);

        return array(
            'hash'          => $this->hash,
            'ip'            => $this->ip,
            'port'          => $this->port,
            'name'          => $this->serverName,
            'description'   => $this->description,
            'isMainServer'  => $this->isMainServer,
            'maxPlayers'    => $this->maxPlayers,
            'playerCount'   => count($playersOnServer),
            'tps'           => $this->tps,
            'load'          => $this->load,
            'upTime'        => $this->upTime,
            'connectedAt'   => $this->connectedAt,
            'authenticated' => $this->authenticated,
            'players'       => $playersOnServer,
        );
    }

    public function tick()
    {
        if (!$this->connected) {
            return;
        }

        // Heartbeat timeout: 45s. The backend sends an explicit heartbeat every
        // 5s under normal conditions, but this also now gets reset by any real
        // traffic (see handlePacket()) -- the extra margin over the nominal 5s
        // interval is just headroom for a busy backend tick (e.g. world/chunk
        // generation) delaying its own scheduler, not something we expect to
        // hit in practice anymore.
        if (microtime(true) - $this->lastHeartbeat > 45) {
            $this->logger->warning("El servidor {$this->hash} no responde (sin heartbeat)");
            $this->disconnect('Timeout de heartbeat');
            return;
        }

        $this->readPackets();
        $this->flushSendBuffer();
    }

    private function readPackets()
    {
        $data = @socket_read($this->socket, 65535, PHP_BINARY_READ);

        if ($data === false) {
            $error = socket_last_error($this->socket);
            // 11 = EAGAIN (Linux), 10035 = WSAEWOULDBLOCK (Windows) - just no data yet
            if ($error === 11 || $error === 10035 || $error === 0) {
                return; // No data available, not an error
            }
            $this->logger->error("Error de socket en {$this->hash}: [{$error}] " . socket_strerror($error));
            $this->disconnect('Error de socket: ' . socket_strerror($error));
            return;
        }

        if ($data === '') {
            // Empty string means peer closed connection gracefully
            $this->disconnect("Server Connection: {$this->hash} - Conexion cerrada por el otro extremo");
            return;
        }

        $this->receiveBuffer .= $data;

        while (strlen($this->receiveBuffer) >= 4) {
            $pkLen = unpack('N', substr($this->receiveBuffer, 0, 4));
            $pkLen = $pkLen[1];

            if ($pkLen <= 0 || $pkLen > 65535) {
                $this->logger->error("Longitud de paquete invalida desde {$this->hash}: {$pkLen}");
                $this->disconnect('Protocolo invalido');
                return;
            }

            if (strlen($this->receiveBuffer) < 4 + $pkLen) {
                break;
            }

            $packetData = substr($this->receiveBuffer, 4, $pkLen);
            $this->receiveBuffer = substr($this->receiveBuffer, 4 + $pkLen);

            $this->handlePacket($packetData);
        }
    }

    /**
     * Decodifica el paquete, lanza DataPacketReceiveEvent y lo despacha a su
     * handler.
     *
     * El switch de 10 casos que habia aqui (y el switch gemelo de
     * createPacket()) se han sustituido por PacketPool + un mapa
     * id => metodo: anadir un paquete nuevo ya solo toca PacketPool y una
     * linea de este mapa.
     *
     * @param string $data
     */
    private function handlePacket($data)
    {
        if ($data === '') {
            return;
        }

        $packetId = ord($data[0]);
        $packet = PacketPool::create($packetId);

        if ($packet === null) {
            $this->logger->warning("Paquete desconocido 0x" . dechex($packetId) . " de {$this->hash}");
            return;
        }

        // Cualquier paquete reconocido demuestra que la conexion sigue viva,
        // no solo el HeartbeatPacket. El backend real solo manda un heartbeat
        // explicito cada 5s (Synapse::update()) y esa tarea se puede retrasar
        // bastante si el servidor esta ocupado (por ejemplo generando y
        // serializando un golpe de chunks para un jugador que acaba de
        // entrar): justo lo que pasaba aqui, un RedirectPacket de 25KB salia
        // inmediatamente antes de que matasemos la conexion por "timeout",
        // cuando ese mismo trafico probaba que el backend estaba vivo.
        $this->lastHeartbeat = microtime(true);

        $packet->setBuffer($data, 1);
        $packet->decode();

        $event = new DataPacketReceiveEvent($this, $packet);
        $event->call();
        if ($event->isCancelled()) {
            return;
        }

        $handler = isset(self::$HANDLERS[$packetId]) ? self::$HANDLERS[$packetId] : null;
        if ($handler === null) {
            $this->logger->info("Paquete sin handler " . Info::getPacketName($packetId) . " de {$this->hash}");
            return;
        }

        $this->$handler($packet);
    }

    // --- Packet Handlers ---

    /**
     * @param ConnectPacket $pk
     */
    private function handleConnect(ConnectPacket $pk)
    {
        $this->logger->info("Server Connection: {$this->hash} - protocolo v{$pk->protocol}");

        $event = new ServerConnectEvent($this, $pk->protocol, $pk->maxPlayers, $pk->isMainServer, $pk->description);
        $event->call();
        if ($event->isCancelled()) {
            $this->logger->warning("Conexion de {$this->hash} rechazada por un listener: " . $event->getKickMessage());
            $this->sendDisconnect(DisconnectPacket::TYPE_GENERIC, $event->getKickMessage());
            $this->disconnect($event->getKickMessage());
            return;
        }

        if ($pk->protocol !== Info::CURRENT_PROTOCOL) {
            $this->logger->error("Protocolo incorrecto desde {$this->hash}: se esperaba v" . Info::CURRENT_PROTOCOL . ", got v{$pk->protocol}");
            $this->sendDisconnect(DisconnectPacket::TYPE_WRONG_PROTOCOL, "Version de protocolo incorrecta. Se esperaba v" . Info::CURRENT_PROTOCOL);
            return;
        }

        if ($pk->password !== $this->server->getPassword()) {
            $this->logger->error("Contrasena incorrecta desde {$this->hash}");
            $this->sendInformation(InformationPacket::TYPE_LOGIN, InformationPacket::INFO_LOGIN_FAILED);
            $this->disconnect('Autenticacion fallida');
            return;
        }

        $this->authenticated = true;
        $this->maxPlayers = $pk->maxPlayers;
        $this->isMainServer = $pk->isMainServer;
        $this->description = $pk->description;
        $this->serverName = $this->ip . ':' . $this->port;

        $typeStr = $this->isMainServer ? 'MAIN' : 'SUB';
        $this->logger->info("Server Backend: {$this->hash} autenticado ({$this->description}, {$typeStr}, PlayerMax={$this->maxPlayers})");

        $this->sendInformation(InformationPacket::TYPE_LOGIN, InformationPacket::INFO_LOGIN_SUCCESS);

        $this->server->removePendingConnection($this->hash);
        $this->manager->registerServer($this);

        $this->manager->sendClientList($this);

        $authEvent = new ServerAuthenticatedEvent($this);
        $authEvent->call();
    }

    /**
     * @param HeartbeatPacket $pk
     */
    private function handleHeartbeat(HeartbeatPacket $pk)
    {
        $this->lastHeartbeat = microtime(true);
        $this->tps = $pk->tps;
        $this->load = $pk->load;
        $this->upTime = $pk->upTime;

        $event = new ServerHeartbeatEvent($this, $pk->tps, $pk->load, $pk->upTime);
        $event->call();
    }

    /**
     * @param DisconnectPacket $pk
     */
    private function handleDisconnect(DisconnectPacket $pk)
    {
        $this->logger->info("El servidor {$this->hash} se desconecta: {$pk->message}");
        $this->disconnect($pk->message);
    }

    /**
     * @param PlayerLoginPacket $pk
     */
    private function handlePlayerLogin(PlayerLoginPacket $pk)
{
    $packetUuidHex = strtolower(str_replace('-', '', bin2hex($pk->uuid)));

    $internalUuidHex = $this->manager->resolvePlayerUuid($packetUuidHex);

    if ($internalUuidHex === null) {
        $rakProxy = $this->manager->getRakProxy();

        if ($rakProxy !== null) {
            $internalUuidHex = $rakProxy->getSessionUuidHexByBackendUuid(
                $packetUuidHex
            );
        }
    }

    if ($internalUuidHex !== null) {
        $this->manager->movePlayer(
            $internalUuidHex,
            $this->hash
        );

        $this->manager->setBackendUuid(
            $internalUuidHex,
            $packetUuidHex
        );
    } else {
        $internalUuidHex = $packetUuidHex;

        $this->manager->registerPlayer(
            $internalUuidHex,
            $this->hash,
            $pk->address,
            $pk->port
        );
    }

    $this->players[$internalUuidHex] = $this->hash;

    $event = new PlayerLoginEvent(
        $pk->uuid,
        $this,
        $pk->address,
        $pk->port,
        $pk->isFirstTime
    );

    $event->call();

    if ($event->isCancelled()) {
        return;
    }

    $this->forwardLoginPacket($pk);
}

    /**
     * @param PlayerLogoutPacket $pk
     */
    private function handlePlayerLogout(PlayerLogoutPacket $pk)
{
    $uuidHex = strtolower(str_replace('-', '', bin2hex($pk->uuid)));

    $resolvedUuid = $this->manager->resolvePlayerUuid($uuidHex);

    if ($resolvedUuid === null) {
        $this->logger->debug(
            "Logout recibido para jugador no registrado: {$uuidHex}"
        );
        return;
    }

    $currentServer = $this->manager->getPlayerServer($resolvedUuid);

    unset($this->players[$uuidHex]);

    if ($currentServer !== $this->hash) {
        $this->logger->debug(
            "Logout ignorado: {$resolvedUuid} pertenece actualmente a {$currentServer}, recibido de {$this->hash}"
        );
        return;
    }

    $event = new PlayerLogoutEvent(
        $pk->uuid,
        $this,
        $pk->reason
    );

    $event->call();

    $this->logger->info(
        "Jugador {$uuidHex} salió de {$this->hash}"
    );

    $this->manager->unregisterPlayer($resolvedUuid);

    $this->manager->forwardToAllExcept($this, $pk);

    $rakProxy = $this->manager->getRakProxy();

    if ($rakProxy !== null) {
        $rakProxy->handlePlayerLogout(
            $pk->uuid,
            $pk->reason
        );
    }
}

    /**
     * @param RedirectPacket $pk
     */
    private function handleRedirect(RedirectPacket $pk)
    {
        $uuidHex = bin2hex($pk->uuid);
        $this->logger->debug("Redirect de {$this->hash} uuid={$uuidHex} dataLen=" . strlen($pk->mcpeBuffer));

        // Forward to RakLib proxy (player client)
        $rakProxy = $this->manager->getRakProxy();
        if ($rakProxy !== null) {
            $rakProxy->handleRedirectFromBackend($pk->uuid, $pk->mcpeBuffer, $this->hash);
        }
    }

    /**
     * @param TransferPacket $pk
     */
    private function handleTransfer(TransferPacket $pk)
{
    $uuidHex = bin2hex($pk->uuid);

    $event = new PlayerTransferEvent(
        $pk->uuid,
        $this,
        $pk->clientHash
    );

    $event->call();

    if ($event->isCancelled()) {
        $this->logger->info(
            "Traslado de {$uuidHex} cancelado por un listener"
        );
        return;
    }

    $targetHash = $event->getTargetHash();

    $this->logger->info(
        "Traslado solicitado: {$uuidHex} de {$this->hash} a {$targetHash}"
    );

    $target = $this->manager->getServer($targetHash);

    if ($target === null || !$target->isAuthenticated()) {
        $this->logger->warning(
            "El destino {$targetHash} no existe o no esta autenticado"
        );
        return;
    }

    $rakProxy = $this->manager->getRakProxy();

    if ($rakProxy === null) {
        $this->logger->warning(
            "No hay RakLibProxy disponible para trasladar {$uuidHex}"
        );
        return;
    }

    if (!$rakProxy->transferPlayer($pk->uuid, $targetHash)) {
        $this->logger->warning(
            "No se pudo cambiar la ruta RakNet de {$uuidHex}"
        );
        return;
    }

    $this->logger->info(
        "Traslado de {$uuidHex} preparado hacia {$targetHash}"
    );
}

    /**
     * @param BroadcastPacket $pk
     */
    private function handleBroadcast(BroadcastPacket $pk)
    {
        foreach ($pk->entries as $uuid) {
            $uuidHex = bin2hex($uuid);
            $targetHash = $this->manager->getPlayerServer($uuidHex);
            if ($targetHash !== null) {
                $target = $this->manager->getServer($targetHash);
                if ($target !== null) {
                    $redirect = new RedirectPacket();
                    $redirect->uuid = $uuid;
                    $redirect->direct = $pk->direct;
                    $redirect->mcpeBuffer = $pk->payload;
                    $target->sendPacket($redirect);
                }
            }
        }
    }

    /**
     * @param FastPlayerListPacket $pk
     */
    private function handleFastPlayerList(FastPlayerListPacket $pk)
    {
        $uuidHex = bin2hex($pk->sendTo);
        $targetHash = $this->manager->getPlayerServer($uuidHex);
        if ($targetHash !== null) {
            $target = $this->manager->getServer($targetHash);
            if ($target !== null) {
                $target->sendPacket($pk);
            }
        }
    }

    // --- Send Methods ---

    /**
     * @param \kuoto\protocol\DataPacket $pk
     */
    public function sendPacket($pk)
    {
        if (!$this->connected) {
            return;
        }

        if ($pk instanceof DataPacket) {
            $event = new DataPacketSendEvent($this, $pk);
            $event->call();
            if ($event->isCancelled()) {
                return;
            }
        }

        $pk->encode();
        $buffer = $pk->getBuffer();
        $this->queueFrame($buffer);
    }

    /**
     * @param string $buffer
     */
    public function sendRawPacket($buffer)
    {
        if (!$this->connected) {
            return;
        }

        $this->queueFrame($buffer);
    }

    /**
     * @param string $buffer
     */
    private function queueFrame($buffer)
    {
        $this->sendBuffer .= pack('N', strlen($buffer)) . $buffer;
        $this->flushSendBuffer();
    }

    /**
     * This socket is non-blocking (see SynapseServer::acceptConnections(), which calls
     * socket_set_nonblock() on every accepted client socket), so socket_write() is free to accept
     * fewer bytes than requested whenever the kernel's send buffer is full -- easy to hit when a
     * lot of data (e.g. a burst of chunk data being relayed) gets queued back to back. The old code
     * fired a single socket_write() and ignored how much it actually sent, so anything left over
     * was silently dropped, permanently desyncing the length-prefixed framing that the other side's
     * readPackets() depends on. Now unsent bytes stay queued and get retried on the next tick().
     */
    private function flushSendBuffer()
    {
        while ($this->sendBuffer !== '' && $this->connected) {
            $written = @socket_write($this->socket, $this->sendBuffer);

            if ($written === false) {
                $error = socket_last_error($this->socket);
                socket_clear_error($this->socket);
                if ($error === 11 || $error === 10035) {
                    break; // EAGAIN / WSAEWOULDBLOCK -- socket can't take more right now, retry next tick
                }
                break; // real error; readPackets()/tick() will notice the disconnect
            }

            if ($written === 0) {
                break; // nothing accepted this pass, retry next tick
            }

            if ($written < strlen($this->sendBuffer)) {
                $this->sendBuffer = substr($this->sendBuffer, $written);
                break; // partial write: keep the remainder queued, don't spin, retry next tick
            }

            $this->sendBuffer = '';
        }
    }

    /**
     * @param int $type
     * @param string $message
     */
    public function sendInformation($type, $message)
    {
        $pk = new InformationPacket();
        $pk->type = $type;
        $pk->message = $message;
        $this->sendPacket($pk);
    }

    /**
     * @param int $type
     * @param string $message
     */
    public function sendDisconnect($type, $message)
    {
        $pk = new DisconnectPacket();
        $pk->type = $type;
        $pk->message = $message;
        $this->sendPacket($pk);
    }

    /**
     * @param string $uuid
     * @param string $reason
     */
    public function sendPlayerLogout($uuid, $reason)
    {
        $pk = new PlayerLogoutPacket();
        $pk->uuid = $uuid;
        $pk->reason = $reason;
        $this->sendPacket($pk);
    }

    /**
     * @param string $reason
     */
    public function disconnect($reason = 'Desconectado')
    {
        if (!$this->connected) {
            return;
        }

        $this->connected = false;
        $this->authenticated = false;

        $event = new ServerDisconnectEvent($this, $reason);
        $event->call();

        $this->manager->unregisterPlayersOnServer($this->hash);
        $this->players = array();

        $this->manager->unregisterServer($this);
        $this->server->removePendingConnection($this->hash);

        @socket_close($this->socket);

        $this->logger->info("Servidor {$this->hash} desconectado: {$reason}");
    }

    private function forwardLoginPacket(PlayerLoginPacket $pk)
{
    $this->manager->forwardToAllExcept($this, $pk);
}
}
