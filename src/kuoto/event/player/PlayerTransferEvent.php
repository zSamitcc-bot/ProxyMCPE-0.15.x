<?php

namespace kuoto\event\player;

use kuoto\event\Cancellable;
use kuoto\event\CancellableTrait;
use kuoto\network\ClientConnection;

/**
 * Se lanza cuando un backend pide mover a un jugador a otro servidor
 * (TransferPacket).
 *
 * Se puede redirigir el destino con setTargetHash() o bloquear el traslado
 * cancelando el evento.
 */
class PlayerTransferEvent extends PlayerEvent implements Cancellable
{
    use CancellableTrait;

    /** @var ClientConnection */
    private $origin;
    /** @var string */
    private $targetHash;

    /**
     * @param string $uuid binario
     * @param ClientConnection $origin
     * @param string $targetHash
     */
    public function __construct($uuid, ClientConnection $origin, $targetHash)
    {
        parent::__construct($uuid);
        $this->origin = $origin;
        $this->targetHash = $targetHash;
    }

    /** @return ClientConnection */
    public function getOrigin()
    {
        return $this->origin;
    }

    /** @return string */
    public function getTargetHash()
    {
        return $this->targetHash;
    }

    /** @param string $hash */
    public function setTargetHash($hash)
    {
        $this->targetHash = $hash;
    }
}
