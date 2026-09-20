<?php

namespace kuoto\command\defaults;

use kuoto\command\Command;
use kuoto\command\CommandMap;
use kuoto\server\SynapseServer;
use kuoto\utils\TextFormat;

class HelpCommand extends Command
{
    /** @var CommandMap */
    private $map;

    public function __construct(SynapseServer $proxy, CommandMap $map)
    {
        parent::__construct(
            $proxy,
            'help',
            'Muestra esta ayuda',
            'help',
            array('h', '?')
        );

        $this->map = $map;
    }

    public function execute($args)
    {
        $reset = TextFormat::RESET;
        $bold = TextFormat::BOLD;
        $aqua = TextFormat::AQUA;
        $gray = TextFormat::GRAY;
        $white = TextFormat::WHITE;
        $yellow = TextFormat::YELLOW;

        $commands = array_values($this->map->getCommands());

        $perPage = 7;
        $totalPages = max(1, (int) ceil(count($commands) / $perPage));

        $page = 1;

        if (isset($args[0]) && is_numeric($args[0])) {
            $page = (int) $args[0];
        }

        if ($page < 1) {
            $page = 1;
        }

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $start = ($page - 1) * $perPage;
        $pageCommands = array_slice($commands, $start, $perPage);

        echo "\n";

        echo $white . $bold;
        echo "  Kuoto Proxy";
        echo $reset . "\n";

        echo $gray;
        echo "  Comandos disponibles - Página ";
        echo $yellow . $page;
        echo $gray . "/" . $totalPages;
        echo $reset . "\n\n";

        foreach ($pageCommands as $command) {
            $label = $command->getName();

            $aliases = $command->getAliases();

            if (count($aliases) > 0) {
                $label .= " / " . implode(" / ", $aliases);
            }

            echo "  ";
            echo $aqua . $bold . TextFormat::pad($label, 25);
            echo $reset;
            echo $gray . $command->getDescription();
            echo $reset . "\n";
        }

        echo "\n";

        if ($totalPages > 1) {
            echo $gray . "  Página ";
            echo $white . $page;
            echo $gray . " de ";
            echo $white . $totalPages;
            echo $reset . "\n";

            echo $gray . "  Usa ";
            echo $yellow . "/help " . ($page < $totalPages ? ($page + 1) : 1);
            echo $gray . " para cambiar de página.";
            echo $reset . "\n";
        }

        echo "\n";

        return true;
    }
}
