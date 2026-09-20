<?php

namespace kuoto\server;

use kuoto\network\ClientConnection;
use kuoto\network\RakLibProxy;
use kuoto\protocol\DataPacket;
use kuoto\protocol\InformationPacket;

class ServerManager
{
    private $servers = array();
    private $players = array();
    private $nameToUuid = array();
    private $backendUuidToUuid = array();
    private $logger;
    private $rakProxy = null;

    public function __construct($logger)
    {
        $this->logger = $logger;
    }

    public function setRakProxy(RakLibProxy $rakProxy)
    {
        $this->rakProxy = $rakProxy;
    }

    public function getRakProxy()
    {
        return $this->rakProxy;
    }

    private function normalizeUuid($uuidHex)
    {
        return strtolower(str_replace('-', '', $uuidHex));
    }

    public function registerServer(ClientConnection $server)
    {
        $this->servers[$server->getHash()] = $server;
    }

    public function unregisterServer($hash)
{
    if ($hash instanceof ClientConnection) {
        $hash = $hash->getHash();
    }

    if (!is_string($hash)) {
        return false;
    }

    unset($this->servers[$hash]);

    return true;
}

    public function getServer($hash)
    {
        return isset($this->servers[$hash])
            ? $this->servers[$hash]
            : null;
    }

    public function getServers()
    {
        return $this->servers;
    }

    public function getAuthenticatedServers()
    {
        $result = array();

        foreach ($this->servers as $hash => $server) {
            if ($server->isAuthenticated()) {
                $result[$hash] = $server;
            }
        }

        return $result;
    }

    public function getServerCount()
    {
        return count($this->servers);
    }

    public function getAuthenticatedServerCount()
    {
        return count($this->getAuthenticatedServers());
    }

    public function registerPlayer($uuidHex, $serverHash, $ip = '', $port = 0)
    {
        $uuidHex = $this->normalizeUuid($uuidHex);

        if (isset($this->backendUuidToUuid[$uuidHex])) {
            $mapped = $this->backendUuidToUuid[$uuidHex];

            if (isset($this->players[$mapped])) {
                $uuidHex = $mapped;
            }
        }

        if (isset($this->players[$uuidHex])) {
            $this->players[$uuidHex]['server'] = $serverHash;

            if ($ip !== '') {
                $this->players[$uuidHex]['ip'] = $ip;
            }

            if ($port > 0) {
                $this->players[$uuidHex]['port'] = $port;
            }

            return $uuidHex;
        }

        $this->players[$uuidHex] = array(
            'server' => $serverHash,
            'ip' => $ip,
            'port' => $port,
            'backendUuid' => null,
            'name' => null
        );

        return $uuidHex;
    }

    public function movePlayer($uuidHex, $serverHash)
    {
        $uuidHex = $this->normalizeUuid($uuidHex);

        $resolved = $this->resolvePlayerUuid($uuidHex);

        if ($resolved !== null) {
            $uuidHex = $resolved;
        }

        if (!isset($this->players[$uuidHex])) {
            return false;
        }

        $this->players[$uuidHex]['server'] = $serverHash;

        return true;
    }

    public function updatePlayerUuid($oldUuidHex, $newUuidHex)
    {
        $oldUuidHex = $this->normalizeUuid($oldUuidHex);
        $newUuidHex = $this->normalizeUuid($newUuidHex);

        if (!isset($this->players[$oldUuidHex])) {
            return false;
        }

        if ($oldUuidHex === $newUuidHex) {
            return true;
        }

        $info = $this->players[$oldUuidHex];

        unset($this->players[$oldUuidHex]);

        $this->players[$newUuidHex] = $info;

        if ($info['backendUuid'] !== null) {
            $backendUuid = $this->normalizeUuid($info['backendUuid']);
            $this->backendUuidToUuid[$backendUuid] = $newUuidHex;
        }

        foreach ($this->nameToUuid as $name => $uuid) {
            if ($this->normalizeUuid($uuid) === $oldUuidHex) {
                $this->nameToUuid[$name] = $newUuidHex;
            }
        }

        return true;
    }

    public function setBackendUuid($uuidHex, $backendUuidHex)
    {
        $uuidHex = $this->normalizeUuid($uuidHex);
        $backendUuidHex = $this->normalizeUuid($backendUuidHex);

        $resolved = $this->resolvePlayerUuid($uuidHex);

        if ($resolved !== null) {
            $uuidHex = $resolved;
        }

        if (!isset($this->players[$uuidHex])) {
            return false;
        }

        if (isset($this->players[$uuidHex]['backendUuid'])) {
            $oldBackend = $this->normalizeUuid(
                $this->players[$uuidHex]['backendUuid']
            );

            if ($oldBackend !== $backendUuidHex) {
                unset($this->backendUuidToUuid[$oldBackend]);
            }
        }

        if (isset($this->backendUuidToUuid[$backendUuidHex])) {
            $oldInternal = $this->backendUuidToUuid[$backendUuidHex];

            if ($oldInternal !== $uuidHex && isset($this->players[$oldInternal])) {
                if (isset($this->players[$oldInternal]['backendUuid'])) {
                    unset(
                        $this->backendUuidToUuid[
                            $this->normalizeUuid(
                                $this->players[$oldInternal]['backendUuid']
                            )
                        ]
                    );
                }

                $this->players[$oldInternal]['backendUuid'] = null;
            }
        }

        $this->players[$uuidHex]['backendUuid'] = $backendUuidHex;
        $this->backendUuidToUuid[$backendUuidHex] = $uuidHex;

        return true;
    }

    public function resolvePlayerUuid($uuidHex)
    {
        $uuidHex = $this->normalizeUuid($uuidHex);

        if (isset($this->players[$uuidHex])) {
            return $uuidHex;
        }

        if (isset($this->backendUuidToUuid[$uuidHex])) {
            $internal = $this->backendUuidToUuid[$uuidHex];

            if (isset($this->players[$internal])) {
                return $internal;
            }

            unset($this->backendUuidToUuid[$uuidHex]);
        }

        return null;
    }

    public function unregisterPlayer($uuidHex)
    {
        $uuidHex = $this->normalizeUuid($uuidHex);

        $resolved = $this->resolvePlayerUuid($uuidHex);

        if ($resolved !== null) {
            $uuidHex = $resolved;
        }

        if (!isset($this->players[$uuidHex])) {
            unset($this->nameToUuid[$uuidHex]);
            unset($this->backendUuidToUuid[$uuidHex]);
            return false;
        }

        if (isset($this->players[$uuidHex]['name'])
            && $this->players[$uuidHex]['name'] !== null) {

            $name = strtolower($this->players[$uuidHex]['name']);

            if (isset($this->nameToUuid[$name])
                && $this->normalizeUuid($this->nameToUuid[$name]) === $uuidHex) {

                unset($this->nameToUuid[$name]);
            }
        }

        if (isset($this->players[$uuidHex]['backendUuid'])
            && $this->players[$uuidHex]['backendUuid'] !== null) {

            $backendUuid = $this->normalizeUuid(
                $this->players[$uuidHex]['backendUuid']
            );

            if (isset($this->backendUuidToUuid[$backendUuid])
                && $this->backendUuidToUuid[$backendUuid] === $uuidHex) {

                unset($this->backendUuidToUuid[$backendUuid]);
            }
        }

        unset($this->backendUuidToUuid[$uuidHex]);
        unset($this->players[$uuidHex]);

        return true;
    }

    public function unregisterPlayersOnServer($serverHash)
    {
        $remove = array();

        foreach ($this->players as $uuidHex => $info) {
            if ($info['server'] === $serverHash) {
                $remove[] = $uuidHex;
            }
        }

        foreach ($remove as $uuidHex) {
            $this->unregisterPlayer($uuidHex);
        }
    }

    public function getPlayerServer($uuidHex)
    {
        $uuidHex = $this->resolvePlayerUuid($uuidHex);

        if ($uuidHex === null) {
            return null;
        }

        return isset($this->players[$uuidHex]['server'])
            ? $this->players[$uuidHex]['server']
            : null;
    }

    public function getPlayerInfo($uuidHex)
    {
        $uuidHex = $this->resolvePlayerUuid($uuidHex);

        if ($uuidHex === null) {
            return null;
        }

        return isset($this->players[$uuidHex])
            ? $this->players[$uuidHex]
            : null;
    }

    public function setPlayerName($uuidHex, $name)
    {
        $uuidHex = $this->resolvePlayerUuid($uuidHex);

        if ($uuidHex === null) {
            return false;
        }

        $oldName = isset($this->players[$uuidHex]['name'])
            ? $this->players[$uuidHex]['name']
            : null;

        if ($oldName !== null) {
            unset($this->nameToUuid[strtolower($oldName)]);
        }

        $this->players[$uuidHex]['name'] = $name;
        $this->nameToUuid[strtolower($name)] = $uuidHex;

        return true;
    }

    public function getPlayerName($uuidHex)
    {
        $uuidHex = $this->resolvePlayerUuid($uuidHex);

        if ($uuidHex === null) {
            return null;
        }

        return isset($this->players[$uuidHex]['name'])
            ? $this->players[$uuidHex]['name']
            : null;
    }

    public function getUuidByName($name)
    {
        $name = strtolower($name);

        if (!isset($this->nameToUuid[$name])) {
            return null;
        }

        $uuid = $this->normalizeUuid($this->nameToUuid[$name]);

        if (!isset($this->players[$uuid])) {
            unset($this->nameToUuid[$name]);
            return null;
        }

        return $uuid;
    }

    public function getPlayerCount()
    {
        return count($this->players);
    }

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

    public function getAllPlayers()
    {
        return $this->players;
    }

    public function forwardToAllExcept(ClientConnection $except, DataPacket $packet)
    {
        foreach ($this->servers as $server) {
            if ($server === $except) {
                continue;
            }

            if (!$server->isAuthenticated()) {
                continue;
            }

            $server->sendPacket($packet);
        }
    }

    public function forwardToAll(DataPacket $packet)
    {
        foreach ($this->servers as $server) {
            if (!$server->isAuthenticated()) {
                continue;
            }

            $server->sendPacket($packet);
        }
    }

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
    $pk->message = json_encode(array(
        'clientList' => $clientList
    ));

    $target->sendPacket($pk);

    $this->logger->debug(
        "Sent client list to " .
        $target->getHash() .
        " (" .
        count($clientList) .
        " servers)"
    );
}

    public function broadcastClientList()
{
    foreach ($this->servers as $server) {
        if (!$server->isAuthenticated()) {
            continue;
        }

        $this->sendClientList($server);
    }
}

    public function getStats()
    {
        return array(
            'servers' => count($this->servers),
            'authenticated' => $this->getAuthenticatedServerCount(),
            'players' => count($this->players)
        );
    }
}