<?php

namespace kuoto\event\proxy;

use kuoto\event\Event;
use kuoto\server\SynapseServer;

/**
 * Se lanza al inicio del apagado, cuando los backends y jugadores todavia
 * siguen conectados (buen momento para guardar datos o avisar).
 */
class ProxyShutdownEvent extends Event
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
