<?php

namespace kuoto\command;

use kuoto\server\ServerManager;
use kuoto\server\SynapseServer;
use kuoto\utils\Logger;

/**
 * Comando de consola. Cada comando vive en su propia clase en lugar de ser un
 * "case" mas dentro de un switch gigante.
 */
abstract class Command
{
    /** @var string */
    private $name;
    /** @var string[] */
    private $aliases;
    /** @var string */
    private $description;
    /** @var string */
    private $usage;
    /** @var SynapseServer */
    protected $proxy;

    /**
     * @param SynapseServer $proxy
     * @param string $name
     * @param string $description
     * @param string $usage
     * @param string[] $aliases
     */
    public function __construct(SynapseServer $proxy, $name, $description, $usage = '', array $aliases = array())
    {
        $this->proxy = $proxy;
        $this->name = $name;
        $this->description = $description;
        $this->usage = $usage === '' ? $name : $usage;
        $this->aliases = $aliases;
    }

    /**
     * @param string $args resto de la linea tras el nombre del comando
     * @return bool false si el uso fue incorrecto
     */
    abstract public function execute($args);

    /** @return string */
    public function getName()
    {
        return $this->name;
    }

    /** @return string[] */
    public function getAliases()
    {
        return $this->aliases;
    }

    /** @return string */
    public function getDescription()
    {
        return $this->description;
    }

    /** @return string */
    public function getUsage()
    {
        return $this->usage;
    }

    /** @return ServerManager */
    protected function getManager()
    {
        return $this->proxy->getManager();
    }

    /** @return Logger */
    protected function getLogger()
    {
        return $this->proxy->getLogger();
    }

    /**
     * Avisa del uso correcto del comando.
     * @return bool siempre false, para poder hacer "return $this->sendUsage();"
     */
    protected function sendUsage()
    {
        $this->getLogger()->warning('Uso: ' . $this->getUsage());
        return false;
    }
}
