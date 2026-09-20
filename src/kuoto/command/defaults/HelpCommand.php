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
        parent::__construct($proxy, 'help', 'Muestra esta ayuda', 'help', array('h', '?'));
        $this->map = $map;
    }

    public function execute($args)
    {
        $c = TextFormat::RESET;
        $b = TextFormat::BOLD;
        $aqua = TextFormat::AQUA;
        $gray = TextFormat::GRAY;
        $white = TextFormat::WHITE;
        $yellow = TextFormat::YELLOW;
        $darkGray = TextFormat::DARK_GRAY;

        $separator = $darkGray . str_repeat('-', 52) . $c . "\n";

        echo "\n" . $separator;
        echo "{$white}{$b}  Comandos disponibles:{$c}\n";
        echo $separator;

        foreach ($this->map->getCommands() as $command) {
            $label = $command->getName();
            if (count($command->getAliases()) > 0) {
                $label .= ' / ' . implode(' / ', $command->getAliases());
            }
            echo '  ' . $aqua . $b . TextFormat::pad($label, 22) . $c
                . $gray . $command->getDescription() . $c . "\n";
        }

        echo $separator;
        echo "{$darkGray}  Kuoto Proxy - escribe {$yellow}help{$darkGray} en cualquier momento{$c}\n";
        echo $separator . "\n";

        return true;
    }
}
