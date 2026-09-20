<?php

namespace kuoto\event\proxy;

use kuoto\event\Event;
use kuoto\server\SynapseServer;

/**
 * Se lanza cuando el proxy ya tiene sus sockets abiertos y esta listo para
 * aceptar backends y jugadores.
 */
class ProxyStartEvent extends Event
{
    /** @var SynapseServer */
    private $proxy;

    /** @param SynapseServer $proxy */
    public function __construct(SynapseServer $proxy)
    {
        $this->proxy = $proxy;
    }

    /** @return SynapseServer */
    public function getProxy()
    {
        return $this->proxy;
    }
}
