<?php

namespace kuoto\event\console;

use kuoto\event\Cancellable;
use kuoto\event\CancellableTrait;
use kuoto\event\Event;

/**
 * Se lanza antes de ejecutar un comando escrito en la consola del proxy.
 *
 * Permite reescribir el comando o sus argumentos, y cancelarlo impide que se
 * ejecute (util para implementar comandos propios desde un listener).
 */
class ConsoleCommandEvent extends Event implements Cancellable
{
    use CancellableTrait;

    /** @var string */
    private $command;
    /** @var string */
    private $args;

    /**
     * @param string $command nombre del comando, en minusculas
     * @param string $args resto de la linea
     */
    public function __construct($command, $args)
    {
        $this->command = $command;
        $this->args = $args;
    }

    /** @return string */
    public function getCommand()
    {
        return $this->command;
    }

    /** @param string $command */
    public function setCommand($command)
    {
        $this->command = strtolower(trim($command));
    }

    /** @return string */
    public function getArgs()
    {
        return $this->args;
    }

    /** @param string $args */
    public function setArgs($args)
    {
        $this->args = $args;
    }

    /** @return string linea completa */
    public function getLine()
    {
        return $this->args === '' ? $this->command : $this->command . ' ' . $this->args;
    }
}
