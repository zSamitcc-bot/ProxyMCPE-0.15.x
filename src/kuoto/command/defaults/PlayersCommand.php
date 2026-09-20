<?php

namespace kuoto\command\defaults;

use kuoto\command\Command;
use kuoto\server\SynapseServer;
use kuoto\utils\TextFormat;

class PlayersCommand extends Command
{
    public function __construct(SynapseServer $proxy)
    {
        parent::__construct(
            $proxy,
            'players',
            'Lista los jugadores conectados',
            'players',
            array('p')
        );
    }

    public function execute($args)
    {
        $players = $this->getManager()->getAllPlayers();

        if (empty($players)) {
            $this->getLogger()->info(
                TextFormat::GRAY . 'No hay jugadores conectados.'
            );

            return true;
        }

        $reset = TextFormat::RESET;
        $bold = TextFormat::BOLD;
        $white = TextFormat::WHITE;
        $green = TextFormat::GREEN;
        $gray = TextFormat::GRAY;
        $yellow = TextFormat::YELLOW;

        echo "\n";

        echo $green . $bold;
        echo "  Jugadores conectados";
        echo $gray . " (" . count($players) . ")";
        echo $reset . "\n\n";

        echo $white . $bold;
        echo "  " . TextFormat::pad("Jugador", 22);
        echo TextFormat::pad("Servidor", 24);
        echo "IP:Puerto";
        echo $reset . "\n";

        echo "\n";

        foreach ($players as $uuid => $info) {
            $name = isset($info['name']) && $info['name'] !== null
                ? $info['name']
                : 'player';

            $server = isset($info['server']) && $info['server'] !== ''
                ? $info['server']
                : 'Desconocido';

            $ip = isset($info['ip']) && $info['ip'] !== ''
                ? $info['ip']
                : '0.0.0.0';

            $port = isset($info['port']) && $info['port'] > 0
                ? $info['port']
                : '0';

            echo "  ";
            echo $white . TextFormat::pad($name, 22);
            echo $green . TextFormat::pad($server, 24);
            echo $gray . $ip . ":" . $port;
            echo $reset . "\n";
        }

        echo "\n";

        $this->getLogger()->info(
            "Jugadores conectados: " . count($players)
        );

        return true;
    }
}
