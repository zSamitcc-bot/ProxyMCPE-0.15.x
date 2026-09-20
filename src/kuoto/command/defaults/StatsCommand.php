<?php

namespace kuoto\command\defaults;

use kuoto\command\Command;
use kuoto\event\EventManager;
use kuoto\server\SynapseServer;
use kuoto\utils\TextFormat;

class StatsCommand extends Command
{
    public function __construct(SynapseServer $proxy)
    {
        parent::__construct($proxy, 'stats', 'Muestra estadisticas del proxy', 'stats', array('s'));
    }

    public function execute($args)
    {
        $stats = $this->getManager()->getStats();
        $uptime = (int) $this->proxy->getUptime();

        $hours = (int) floor($uptime / 3600);
        $minutes = (int) floor(($uptime % 3600) / 60);
        $seconds = $uptime % 60;

        echo PHP_EOL;
        echo '  ' . TextFormat::LIGHT_PURPLE . TextFormat::BOLD . 'Estadisticas de Kuoto' . TextFormat::RESET . PHP_EOL;
        echo PHP_EOL;

        echo '  ' . TextFormat::GRAY . 'Servidores: ' . TextFormat::RESET .
            TextFormat::YELLOW . TextFormat::BOLD .
            $stats['authenticated'] .
            TextFormat::RESET . TextFormat::GRAY . ' / ' .
            $stats['servers'] .
            TextFormat::RESET . PHP_EOL;

        echo '  ' . TextFormat::GRAY . 'Jugadores: ' . TextFormat::RESET .
            TextFormat::GREEN . TextFormat::BOLD .
            $stats['players'] .
            TextFormat::RESET . PHP_EOL;

        echo '  ' . TextFormat::GRAY . 'Uptime: ' . TextFormat::RESET .
            TextFormat::AQUA . TextFormat::BOLD .
            $hours . 'h ' . $minutes . 'm ' . $seconds . 's' .
            TextFormat::RESET . PHP_EOL;

        echo '  ' . TextFormat::GRAY . 'Puerto RakLib: ' . TextFormat::RESET .
            TextFormat::AQUA . TextFormat::BOLD .
            $this->proxy->getRakPort() .
            TextFormat::RESET . PHP_EOL;

        echo '  ' . TextFormat::GRAY . 'Listeners: ' . TextFormat::RESET .
            TextFormat::AQUA . TextFormat::BOLD .
            EventManager::getInstance()->getHandlerCount() .
            TextFormat::RESET . PHP_EOL;

        echo PHP_EOL;

        return true;
    }
}