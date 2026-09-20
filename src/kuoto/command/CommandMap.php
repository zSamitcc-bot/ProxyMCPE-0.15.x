<?php

namespace kuoto\command;

use kuoto\command\defaults\HelpCommand;
use kuoto\command\defaults\KickCommand;
use kuoto\command\defaults\ListCommand;
use kuoto\command\defaults\PlayersCommand;
use kuoto\command\defaults\SayCommand;
use kuoto\command\defaults\StatsCommand;
use kuoto\command\defaults\StopCommand;
use kuoto\event\console\ConsoleCommandEvent;
use kuoto\server\SynapseServer;

/**
 * Registro y despacho de comandos.
 *
 * Antes la misma tabla de comandos estaba duplicada literalmente en
 * Console::handleCommand() y en SynapseServer::handleCommand(); cualquier
 * cambio habia que hacerlo dos veces y en la practica solo se usaba una de las
 * dos copias. Ahora hay un unico registro.
 */
class CommandMap
{
    /** @var SynapseServer */
    private $proxy;
    /** @var Command[] nombre => comando */
    private $commands = array();
    /** @var string[] alias => nombre */
    private $aliases = array();

    /** @param SynapseServer $proxy */
    public function __construct(SynapseServer $proxy)
    {
        $this->proxy = $proxy;
        $this->registerDefaults();
    }

    private function registerDefaults()
    {
        $this->register(new HelpCommand($this->proxy, $this));
        $this->register(new ListCommand($this->proxy));
        $this->register(new PlayersCommand($this->proxy));
        $this->register(new StatsCommand($this->proxy));
        $this->register(new KickCommand($this->proxy));
        $this->register(new SayCommand($this->proxy));
        $this->register(new StopCommand($this->proxy));
    }

    /** @param Command $command */
    public function register(Command $command)
    {
        $this->commands[$command->getName()] = $command;
        foreach ($command->getAliases() as $alias) {
            $this->aliases[$alias] = $command->getName();
        }
    }

    /**
     * @param string $name
     * @return Command|null
     */
    public function getCommand($name)
    {
        $name = strtolower($name);
        if (isset($this->aliases[$name])) {
            $name = $this->aliases[$name];
        }
        return isset($this->commands[$name]) ? $this->commands[$name] : null;
    }

    /** @return Command[] */
    public function getCommands()
    {
        return $this->commands;
    }

    /**
     * Lanza ConsoleCommandEvent y, si no se cancela, ejecuta el comando.
     *
     * @param string $line
     * @return bool
     */
    public function dispatch($line)
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }

        $parts = explode(' ', $line, 2);
        $event = new ConsoleCommandEvent(strtolower($parts[0]), isset($parts[1]) ? $parts[1] : '');
        $event->call();

        if ($event->isCancelled()) {
            return true; // un listener se ha encargado del comando
        }

        $command = $this->getCommand($event->getCommand());
        if ($command === null) {
            $this->proxy->getLogger()->warning(
                "Comando desconocido: {$event->getCommand()}. Escribe 'help' para ver la lista."
            );
            return false;
        }

        return $command->execute($event->getArgs()) !== false;
    }
}
