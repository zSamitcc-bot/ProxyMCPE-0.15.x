<?php

namespace kuoto\command\defaults;

use kuoto\command\Command;
use kuoto\server\SynapseServer;
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

        echo PHP_EOL;
        echo '  ' . TextFormat::GOLD . TextFormat::BOLD . 'Servidores conectados (' . count($servers) . ')' . TextFormat::RESET . PHP_EOL;
        echo PHP_EOL;

        echo '  ' .
            TextFormat::GRAY . TextFormat::pad('Direccion', 24) .
            TextFormat::pad('Tipo', 8) .
            TextFormat::pad('Jugadores', 12) .
            TextFormat::pad('TPS', 8) .
            TextFormat::pad('Estado', 10) .
            TextFormat::RESET . PHP_EOL;

        echo PHP_EOL;

        foreach ($servers as $server) {
            $info = $server->getInfo();

            $type = $info['isMainServer']
                ? TextFormat::GREEN . 'MAIN'
                : TextFormat::BLUE . 'SUB';

            $tps = number_format($info['tps'], 1);

            if ($info['tps'] >= 18) {
                $tpsColor = TextFormat::GREEN;
            } elseif ($info['tps'] >= 14) {
                $tpsColor = TextFormat::YELLOW;
            } else {
                $tpsColor = TextFormat::RED;
            }

            $status = $server->isAuthenticated()
                ? TextFormat::GREEN . 'ONLINE'
                : TextFormat::RED . 'OFFLINE';

            echo '  ' .
                TextFormat::WHITE . TextFormat::pad($info['hash'], 24) .
                TextFormat::pad($type, 8) .
                TextFormat::GRAY . TextFormat::pad(
                    $info['playerCount'] . '/' . $info['maxPlayers'],
                    12
                ) .
                TextFormat::pad($tpsColor . $tps, 8) .
                TextFormat::pad($status, 10) .
                TextFormat::RESET . PHP_EOL;
        }

        echo PHP_EOL;

        return true;
    }
}