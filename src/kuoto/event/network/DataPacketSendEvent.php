<?php

namespace kuoto\event\network;

use kuoto\event\Cancellable;
use kuoto\event\CancellableTrait;
use kuoto\event\Event;
use kuoto\network\ClientConnection;
use kuoto\protocol\DataPacket;

/**
 * Se lanza justo antes de enviar un paquete Synapse a un backend.
 * Cancelarlo impide el envio.
 */
class DataPacketSendEvent extends Event implements Cancellable
{
    use CancellableTrait;

    /** @var ClientConnection */
    private $target;
    /** @var DataPacket */
    private $packet;

    /**
     * @param ClientConnection $target
     * @param DataPacket $packet
     */
    public function __construct(ClientConnection $target, DataPacket $packet)
    {
        $this->target = $target;
        $this->packet = $packet;
    }

    /** @return ClientConnection */
    public function getTarget()
    {
        return $this->target;
    }

    /** @return DataPacket */
    public function getPacket()
    {
        return $this->packet;
    }
}
