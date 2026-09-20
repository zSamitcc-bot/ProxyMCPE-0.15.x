<?php

namespace kuoto\event\proxy;

use kuoto\event\Cancellable;
use kuoto\event\CancellableTrait;
use kuoto\event\Event;

/**
 * Se lanza cuando un cliente MCPE hace ping al proxy para ver la lista de
 * servidores. Permite cambiar el MOTD, el contador de jugadores, etc.
 *
 * Cancelarlo hace que el proxy no responda (el servidor aparece offline).
 */
class ProxyPingEvent extends Event implements Cancellable
{
    use CancellableTrait;

    /** @var string */
    private $address;
    /** @var int */
    private $port;
    /** @var string */
    private $motd;
    /** @var string */
    private $subMotd;
    /** @var int */
    private $playerCount;
    /** @var int */
    private $maxPlayers;
    /** @var string */
    private $gamemode;

    /**
     * @param string $address
     * @param int $port
     * @param string $motd
     * @param string $subMotd
     * @param int $playerCount
     * @param int $maxPlayers
     * @param string $gamemode
     */
    public function __construct($address, $port, $motd, $subMotd, $playerCount, $maxPlayers, $gamemode)
    {
        $this->address = $address;
        $this->port = $port;
        $this->motd = $motd;
        $this->subMotd = $subMotd;
        $this->playerCount = $playerCount;
        $this->maxPlayers = $maxPlayers;
        $this->gamemode = $gamemode;
    }

    /** @return string */
    public function getAddress()
    {
        return $this->address;
    }

    /** @return int */
    public function getPort()
    {
        return $this->port;
    }

    /** @return string */
    public function getMotd()
    {
        return $this->motd;
    }

    /** @param string $motd */
    public function setMotd($motd)
    {
        // Los ';' separan campos en la cadena MOTD de RakNet: si se cuelan,
        // el cliente interpreta mal el resto del ping.
        $this->motd = str_replace(';', ' ', $motd);
    }

    /** @return string */
    public function getSubMotd()
    {
        return $this->subMotd;
    }

    /** @param string $subMotd */
    public function setSubMotd($subMotd)
    {
        $this->subMotd = str_replace(';', ' ', $subMotd);
    }

    /** @return int */
    public function getPlayerCount()
    {
        return $this->playerCount;
    }

    /** @param int $count */
    public function setPlayerCount($count)
    {
        $this->playerCount = (int) $count;
    }

    /** @return int */
    public function getMaxPlayers()
    {
        return $this->maxPlayers;
    }

    /** @param int $max */
    public function setMaxPlayers($max)
    {
        $this->maxPlayers = (int) $max;
    }

    /** @return string */
    public function getGamemode()
    {
        return $this->gamemode;
    }
}
