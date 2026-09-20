<?php

namespace kuoto\server;

use kuoto\utils\Logger;
use kuoto\network\RakLibProxy;
use kuoto\network\ClientConnection;

use kuoto\protocol\InformationPacket;
use kuoto\protocol\RedirectPacket;
use kuoto\protocol\PlayerLogoutPacket;

class ServerManager
{
    /** @var ClientConnection[] hash => connection */
    private $servers = array();

    /** @var array uuidHex => array{server, ip, port} */
    private $players = array();

    /** @var array nombre en minuscula => uuidHex (interno del proxy) */
    private $nameToUuid = array();

    private $backendUuidToUuid = array();

    /** @var Logger */
    private $logger;

    /** @var RakLibProxy|null */
    private $rakProxy = null;

    /**
     * @param Logger $logger
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Set RakLib proxy reference for forwarding player data
     * @param RakLibProxy $rakProxy
     */
    public function setRakProxy(RakLibProxy $rakProxy)
    {
        $this->rakProxy = $rakProxy;
    }

    /**
     * Get RakLib proxy
     * @return RakLibProxy|null
     */
    public function getRakProxy()
    {
        return $this->rakProxy;
    }

    // --- Server Management ---

    /**
     * @param ClientConnection $server
     */
    public function registerServer(ClientConnection $server)
    {
        $this->servers[$server->getHash()] = $server;
        $this->logger->debug("Server registered: " . $server->getHash() . " (total: " . count($this->servers) . ")");
    }

    /**
     * @param ClientConnection $server
     */
    public function unregisterServer(ClientConnection $server)
    {
        unset($this->servers[$server->getHash()]);
        $this->logger->debug("Server unregistered: " . $server->getHash() . " (total: " . count($this->servers) . ")");
    }

    /**
     * @param string $hash
     * @return ClientConnection|null
     */
    public function getServer($hash)
    {
        return isset($this->servers[$hash]) ? $this->servers[$hash] : null;
    }

    /**
     * @return ClientConnection[]
     */
    public function getServers()
    {
        return $this->servers;
    }

    /**
     * @return int
     */
    public function getServerCount()
    {
        return count($this->servers);
    }

    /**
     * @return ClientConnection[]
     */
    public function getAuthenticatedServers()
    {
        $result = array();
        foreach ($this->servers as $server) {
            if ($server->isAuthenticated()) {
                $result[] = $server;
            }
        }
        return $result;
    }

    // --- Player Management ---

    /**
     * @param string $uuidHex
     * @param string $serverHash
     * @param string $ip
     * @param int $port
     */
    public function registerPlayer($uuidHex, $serverHash, $ip, $port)
    {
        $this->players[$uuidHex] = array(
            'server' => $serverHash,
            'ip'     => $ip,
            'port'   => $port,
        );
        $this->logger->debug("Player {$uuidHex} registered on server {$serverHash}");
    }

    public function movePlayer($uuidHex, $serverHash)
{
    if (!isset($this->players[$uuidHex])) {
        return false;
    }

    $oldHash = $this->players[$uuidHex]['server'];

    $this->players[$uuidHex]['server'] = $serverHash;

    $this->logger->debug(
        "Player {$uuidHex} moved from {$oldHash} to {$serverHash}"
    );

    return true;
}

public function updatePlayerUuid($oldUuidHex, $newUuidHex)
{
    if (!isset($this->players[$oldUuidHex])) {
        return false;
    }

    $this->players[$newUuidHex] = $this->players[$oldUuidHex];

    unset($this->players[$oldUuidHex]);

    $this->logger->debug(
        "Player UUID cambiado {$oldUuidHex} -> {$newUuidHex}"
    );

    return true;
}

    public function setBackendUuid($uuidHex, $backendUuidHex)
{
    $uuidHex = strtolower(str_replace('-', '', $uuidHex));
    $backendUuidHex = strtolower(str_replace('-', '', $backendUuidHex));

    if (!isset($this->players[$uuidHex])) {
        return false;
    }

    $this->players[$uuidHex]['backendUuid'] = $backendUuidHex;
    $this->backendUuidToUuid[$backendUuidHex] = $uuidHex;

    return true;
}

public function resolvePlayerUuid($uuidHex)
{
    $uuidHex = strtolower(str_replace('-', '', $uuidHex));

    if (isset($this->players[$uuidHex])) {
        return $uuidHex;
    }

    if (isset($this->backendUuidToUuid[$uuidHex])) {
        return $this->backendUuidToUuid[$uuidHex];
    }

    return null;
}

    /**
     * @param string $uuidHex
     */
    public function unregisterPlayer($uuidHex)
    {
        if (isset($this->players[$uuidHex]['name'])) {
            $nameKey = strtolower($this->players[$uuidHex]['name']);
            if (isset($this->nameToUuid[$nameKey]) && $this->nameToUuid[$nameKey] === $uuidHex) {
                unset($this->nameToUuid[$nameKey]);
            }
        }
        unset($this->players[$uuidHex]);
        $this->logger->debug("Player {$uuidHex} unregistered");
    }

    /**
     * @param string $uuidHex
     * @return string|null
     */
    public function getPlayerServer($uuidHex)
    {
        return isset($this->players[$uuidHex]['server']) ? $this->players[$uuidHex]['server'] : null;
    }

    /**
     * @param string $uuidHex
     * @return array|null
     */
    public function getPlayerInfo($uuidHex)
    {
        return isset($this->players[$uuidHex]) ? $this->players[$uuidHex] : null;
    }

    /**
     * Asocia un nombre de jugador (aprendido al fisgonear un
     * FastPlayerListPacket que pasa por el proxy) al UUID interno de su
     * sesion. Se usa para poder resolver /kick <nombre> ademas de
     * /kick <uuid>.
     *
     * @param string $uuidHex
     * @param string $name
     */
    public function setPlayerName($uuidHex, $name)
    {
        if ($name === '' || !isset($this->players[$uuidHex])) {
            return;
        }

        $oldName = isset($this->players[$uuidHex]['name']) ? $this->players[$uuidHex]['name'] : null;
        if ($oldName !== null && $oldName !== $name) {
            $oldKey = strtolower($oldName);
            if (isset($this->nameToUuid[$oldKey]) && $this->nameToUuid[$oldKey] === $uuidHex) {
                unset($this->nameToUuid[$oldKey]);
            }
        }

        $this->players[$uuidHex]['name'] = $name;
        $this->nameToUuid[strtolower($name)] = $uuidHex;
    }

    /**
     * @param string $uuidHex
     * @return string|null
     */
    public function getPlayerName($uuidHex)
    {
        return isset($this->players[$uuidHex]['name']) ? $this->players[$uuidHex]['name'] : null;
    }

    /**
     * Busca el UUID interno del proxy a partir de un nombre de jugador
     * (case-insensitive).
     *
     * @param string $name
     * @return string|null
     */
    public function getUuidByName($name)
    {
        $key = strtolower($name);
        return isset($this->nameToUuid[$key]) ? $this->nameToUuid[$key] : null;
    }

    /**
     * @return int
     */
    public function getPlayerCount()
    {
        return count($this->players);
    }

    /**
     * @return array
     */
    public function getAllPlayers()
    {
        return $this->players;
    }

    /**
     * Jugadores actualmente registrados en un backend concreto.
     *
     * ClientConnection::$players nunca se llena (ver handlePlayerLogin),
     * asi que este mapa es la unica fuente real de "quien esta en que
     * servidor" para cosas como el contador de /list.
     *
     * @param string $serverHash
     * @return array uuidHex => array{server, ip, port}
     */
    public function getPlayersOnServer($serverHash)
    {
        $result = array();
        foreach ($this->players as $uuidHex => $info) {
            if ($info['server'] === $serverHash) {
                $result[$uuidHex] = $info;
            }
        }
        return $result;
    }

    /**
     * @param string $serverHash
     * @return int
     */
    public function getPlayerCountForServer($serverHash)
    {
        return count($this->getPlayersOnServer($serverHash));
    }

    /**
     * Quita del mapa a todos los jugadores que estaban en un servidor.
     * Se usa cuando ese backend se desconecta, para que no queden
     * jugadores "fantasma" apuntando a un servidor que ya no existe.
     *
     * @param string $serverHash
     */
    public function unregisterPlayersOnServer($serverHash)
    {
        foreach ($this->players as $uuidHex => $info) {
            if ($info['server'] === $serverHash) {
                unset($this->players[$uuidHex]);
            }
        }
    }

    // --- Forwarding ---

    /**
     * Forward raw packet to all authenticated servers except sender
     *
     * @param ClientConnection $except
     * @param \kuoto\protocol\DataPacket $pk
     */
    public function forwardToAllExcept(ClientConnection $except, $pk)
    {
        $pk->encode();
        $buffer = $pk->getBuffer();

        foreach ($this->servers as $server) {
            if ($server !== $except && $server->isAuthenticated()) {
                $server->sendRawPacket($buffer);
            }
        }
    }

    /**
     * Forward raw packet to all authenticated servers
     *
     * @param \kuoto\protocol\DataPacket $pk
     */
    public function forwardToAll($pk)
    {
        $pk->encode();
        $buffer = $pk->getBuffer();

        foreach ($this->servers as $server) {
            if ($server->isAuthenticated()) {
                $server->sendRawPacket($buffer);
            }
        }
    }

    /**
     * Send client list to a specific server
     *
     * @param ClientConnection $target
     */
    public function sendClientList(ClientConnection $target)
    {
        $clientList = array();
        foreach ($this->servers as $server) {
            if ($server->isAuthenticated() && $server !== $target) {
                $info = $server->getInfo();
                $clientList[$server->getHash()] = array(
                    'ip'          => $info['ip'],
                    'port'        => $info['port'],
                    'playerCount' => $info['playerCount'],
                    'maxPlayers'  => $info['maxPlayers'],
                    'description' => $info['description'],
                    'tps'         => $info['tps'],
                    'load'        => $info['load'],
                );
            }
        }

        $pk = new InformationPacket();
        $pk->type = InformationPacket::TYPE_CLIENT_DATA;
        $pk->message = json_encode(array('clientList' => $clientList));
        $target->sendPacket($pk);

        $this->logger->debug("Sent client list to " . $target->getHash() . " (" . count($clientList) . " servers)");
    }

    /**
     * Broadcast client list to all servers
     */
    public function broadcastClientList()
    {
        foreach ($this->getAuthenticatedServers() as $server) {
            $this->sendClientList($server);
        }
    }

    /**
     * @return array
     */
    public function getStats()
    {
        $servers = array();
        foreach ($this->servers as $server) {
            $servers[] = $server->getInfo();
        }

        return array(
            'totalServers'         => count($this->servers),
            'authenticatedServers' => count($this->getAuthenticatedServers()),
            'totalPlayers'         => count($this->players),
            'servers'              => $servers,
        );
    }
}