<?php

namespace kuoto\command\defaults;

use kuoto\command\Command;
use kuoto\server\SynapseServer;

class SayCommand extends Command
{
    public function __construct(SynapseServer $proxy)
    {
        parent::__construct($proxy, 'say', 'Muestra un mensaje en la consola del proxy', 'say <mensaje>');
    }

    public function execute($args)
    {
        if (trim($args) === '') {
            return $this->sendUsage();
        }

        // NOTA: el protocolo Synapse no tiene un paquete de chat propio; para
        // que esto llegue de verdad al chat de los jugadores hay que enviar un
        // TextPacket de MCPE dentro de un BroadcastPacket, lo que requiere
        // conocer el protocolo del cliente. De momento solo se registra en la
        // consola, igual que hacia la version anterior (que ademas mentia
        // diciendo "Broadcast sent to all servers").
        $this->getLogger()->info('[Consola] ' . $args);
        return true;
    }
}
