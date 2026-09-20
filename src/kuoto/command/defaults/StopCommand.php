<?php

namespace kuoto\command\defaults;

use kuoto\command\Command;
use kuoto\server\SynapseServer;

class StopCommand extends Command
{
    public function __construct(SynapseServer $proxy)
    {
        parent::__construct($proxy, 'stop', 'Apaga el proxy', 'stop', array('quit', 'exit'));
    }

    public function execute($args)
    {
        // El mensaje de apagado lo imprime SynapseServer::shutdown(); aqui solo
        // se pide la parada para no duplicar la misma linea en el log.
        $this->proxy->stop();
        return true;
    }
}
