<?php

namespace kuoto\command\defaults;

use kuoto\command\Command;
use kuoto\server\SynapseServer;
use kuoto\utils\ConsoleTable;
use kuoto\utils\TextFormat;

class PlayersCommand extends Command
{
    public function __construct(SynapseServer $proxy)
    {
        parent::__construct($proxy, 'players', 'Lista los jugadores conectados', 'players', array('p'));
    }

    public function execute($args)
    {
        $players = $this->getManager()->getAllPlayers();

        if (empty($players)) {
            $this->getLogger()->info('No hay jugadores conectados');
            return true;
        }

        $table = new ConsoleTable('JUGADORES CONECTADOS', TextFormat::GREEN);
        $table->setHeaders(array('UUID', 'Servidor', 'IP:Puerto'), array(20, 22, 22));

        foreach ($players as $uuid => $info) {
            $shortUuid = substr($uuid, 0, 8) . '-' . substr($uuid, 8, 4) . '-' . substr($uuid, 12, 4) . '..';
            $table->addRow(array(
                TextFormat::WHITE . $shortUuid,
                TextFormat::GREEN . $info['server'],
                TextFormat::GRAY . $info['ip'] . ':' . $info['port'],
            ));
        }

        $table->display();
        return true;
    }
}
