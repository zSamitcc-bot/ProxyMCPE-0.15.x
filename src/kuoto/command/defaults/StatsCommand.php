<?php

namespace kuoto\command\defaults;

use kuoto\command\Command;
use kuoto\event\EventManager;
use kuoto\server\SynapseServer;
use kuoto\utils\ConsoleTable;
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

        $table = new ConsoleTable('ESTADISTICAS DE KUOTO', TextFormat::LIGHT_PURPLE);
        $table->setHeaders(array(), array(18, 24));

        $table->addRow(array(
            TextFormat::GRAY . 'Servidores:',
            TextFormat::YELLOW . TextFormat::BOLD . $stats['authenticatedServers']
                . TextFormat::RESET . TextFormat::GRAY . ' / ' . $stats['totalServers'],
        ));
        $table->addRow(array(
            TextFormat::GRAY . 'Jugadores:',
            TextFormat::GREEN . TextFormat::BOLD . $stats['totalPlayers'],
        ));
        $table->addRow(array(
            TextFormat::GRAY . 'Uptime:',
            TextFormat::AQUA . TextFormat::BOLD . "{$hours}h {$minutes}m {$seconds}s",
        ));
        $table->addRow(array(
            TextFormat::GRAY . 'Puerto RakLib:',
            TextFormat::AQUA . TextFormat::BOLD . $this->proxy->getRakPort(),
        ));
        $table->addRow(array(
            TextFormat::GRAY . 'Handlers:',
            TextFormat::AQUA . TextFormat::BOLD . EventManager::getInstance()->getHandlerCount(),
        ));

        $table->display();
        return true;
    }
}
