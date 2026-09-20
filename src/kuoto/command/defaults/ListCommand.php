<?php

namespace kuoto\command\defaults;

use kuoto\command\Command;
use kuoto\server\SynapseServer;
use kuoto\utils\ConsoleTable;
use kuoto\utils\TextFormat;

class ListCommand extends Command
{
    public function __construct(SynapseServer $proxy)
    {
        parent::__construct($proxy, 'list', 'Lista los servidores conectados', 'list', array('ls'));
    }

    public function execute($args)
    {
        $servers = $this->getManager()->getServers();

        if (empty($servers)) {
            $this->getLogger()->info('No hay servidores conectados');
            return true;
        }

        $table = new ConsoleTable('SERVIDORES CONECTADOS', TextFormat::GOLD);
        $table->setHeaders(
            array('Direccion', 'Tipo', 'Jugadores', 'TPS', 'Estado'),
            array(22, 4, 10, 5, 8)
        );

        foreach ($servers as $server) {
            $info = $server->getInfo();

            $type = $info['isMainServer']
                ? TextFormat::GREEN . TextFormat::BOLD . 'MAIN'
                : TextFormat::BLUE . TextFormat::BOLD . 'SUB';

            $tps = number_format($info['tps'], 1);
            if ($info['tps'] >= 18) {
                $tpsColor = TextFormat::GREEN;
            } elseif ($info['tps'] >= 14) {
                $tpsColor = TextFormat::YELLOW;
            } else {
                $tpsColor = TextFormat::RED;
            }

            $status = $server->isAuthenticated()
                ? TextFormat::GREEN . TextFormat::BOLD . 'ONLINE'
                : TextFormat::RED . TextFormat::BOLD . 'OFFLINE';

            $table->addRow(array(
                TextFormat::WHITE . $info['hash'],
                $type,
                TextFormat::GRAY . $info['playerCount'] . '/' . $info['maxPlayers'],
                $tpsColor . $tps,
                $status,
            ));
        }

        $table->display();
        return true;
    }
}
