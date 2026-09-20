<?php

namespace kuoto\plugin;

use kuoto\event\EventManager;
use kuoto\event\Listener;
use kuoto\server\SynapseServer;
use kuoto\utils\Logger;

/**
 * Cargador minimo de extensiones.
 *
 * Todo archivo .php dentro de la carpeta plugins/ se incluye al arrancar; las
 * clases que implementen Listener se instancian y se registran en el
 * EventManager automaticamente.
 *
 * Si la clase declara un constructor, se le pasa el SynapseServer, de modo que
 * un plugin puede acceder al manager, al logger o a la consola.
 */
class PluginLoader
{
    /** @var SynapseServer */
    private $proxy;
    /** @var Logger */
    private $logger;
    /** @var string */
    private $directory;
    /** @var Listener[] */
    private $listeners = array();

    /**
     * @param SynapseServer $proxy
     * @param Logger $logger
     * @param string $directory
     */
    public function __construct(SynapseServer $proxy, Logger $logger, $directory)
    {
        $this->proxy = $proxy;
        $this->logger = $logger;
        $this->directory = rtrim($directory, '/\\');
    }

    /**
     * @return int numero de listeners cargados
     */
    public function loadAll()
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false || empty($files)) {
            return 0;
        }

        sort($files);
        $loaded = 0;

        foreach ($files as $file) {
            $before = get_declared_classes();

            try {
                /** @noinspection PhpIncludeInspection */
                require_once $file;
            } catch (\Throwable $e) {
                $this->logger->error(
                    'No se pudo cargar ' . basename($file) . ': ' . get_class($e) . ': ' . $e->getMessage()
                );
                continue;
            }

            $new = array_diff(get_declared_classes(), $before);
            foreach ($new as $class) {
                if (!is_subclass_of($class, Listener::class)) {
                    continue;
                }

                try {
                    $instance = $this->instantiate($class);
                    $handlers = EventManager::getInstance()->registerEvents($instance);
                    $this->listeners[] = $instance;
                    $loaded++;
                    $this->logger->info("Listener cargado: {$class} ({$handlers} handlers)");
                } catch (\Throwable $e) {
                    $this->logger->error(
                        "No se pudo registrar {$class}: " . get_class($e) . ': ' . $e->getMessage()
                    );
                }
            }
        }

        return $loaded;
    }

    /** @return Listener[] */
    public function getListeners()
    {
        return $this->listeners;
    }

    /**
     * @param string $class
     * @return Listener
     */
    private function instantiate($class)
    {
        $reflection = new \ReflectionClass($class);
        if ($reflection->isAbstract()) {
            throw new \RuntimeException("{$class} es abstracta");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfParameters() > 0) {
            return $reflection->newInstance($this->proxy);
        }

        return $reflection->newInstance();
    }
}
