<?php

namespace kuoto\event\player;

use kuoto\event\Event;

/**
 * Base de todos los eventos de jugador. El UUID se guarda en binario (16
 * bytes, tal y como viaja por el protocolo) y se expone tambien en hexadecimal,
 * que es la clave que usa el ServerManager.
 */
abstract class PlayerEvent extends Event
{
    /** @var string binario, 16 bytes */
    protected $uuid;
    /** @var string */
    protected $uuidHex;

    /** @param string $uuid binario */
    public function __construct($uuid)
    {
        $this->uuid = $uuid;
        $this->uuidHex = bin2hex($uuid);
    }

    /** @return string binario, 16 bytes */
    public function getUuid()
    {
        return $this->uuid;
    }

    /** @return string */
    public function getUuidHex()
    {
        return $this->uuidHex;
    }
}
