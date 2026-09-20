<?php

namespace kuoto\server;

use kuoto\command\CommandMap;
use kuoto\console\Console;
use kuoto\event\EventManager;
use kuoto\event\proxy\ProxyShutdownEvent;
use kuoto\event\proxy\ProxyStartEvent;
use kuoto\network\ClientConnection;
use kuoto\network\RakLibProxy;
use kuoto\plugin\PluginLoader;
use kuoto\utils\Logger;
use kuoto\utils\TextFormat;

class SynapseServer
{
    /** @var string */
    private $bindIp;
    /** @var int */
    private $port;
    /** @var string */
    private $password;
    /** @var int */
    private $maxServers;
    /** @var string */
    private $description;
    /** @var string */
    private $motd;
    /** @var string */
    private $subMotd;
    /** @var string */
    private $gamemode;
    /** @var int */
    private $protocol;
    /** @var string */
    private $version;
    /** @var int */
    private $maxPlayers;
    /** @var int */
    private $rakPort;
    /** @var string */
    private $pluginPath;
    /** @var string */
    private $consoleMode;

    /** @var \Socket|resource|null */
    private $socket;
    /** @var Logger */
    private $logger;
    /** @var ServerManager */
    private $manager;
    /** @var EventManager */
    private $eventManager;
    /** @var CommandMap|null */
    private $commandMap = null;
    /** @var Console|null */
    private $console = null;
    /** @var PluginLoader|null */
    private $pluginLoader = null;
    /** @var RakLibProxy|null */
    private $rakProxy = null;

    /** @var bool */
    private $running = false;
    /** @var float */
    private $startTime;
    /** @var float */
    private $tickInterval;

    /** @var ClientConnection[] */
    private $pendingConnections = array();

    /**
     * @param array $config
     */
    public function __construct(array $config)
    {
        $server = isset($config['server']) ? $config['server'] : array();

        $this->bindIp      = $this->get($server, 'bind-ip', '0.0.0.0');
        $this->port        = (int) $this->get($server, 'port', 10305);
        $this->password    = $this->get($server, 'password', '123456');
        $this->maxServers  = (int) $this->get($server, 'max-servers', 50);
        $this->description = $this->get($server, 'description', 'Kuoto Central Server');
        $this->rakPort     = (int) $this->get($server, 'rak-port', 19132);
        $this->motd        = $this->get($server, 'motd', 'Kuoto Proxy');
        $this->subMotd     = $this->get($server, 'sub-motd', 'A Minecraft PE Proxy');
        $this->gamemode    = $this->get($server, 'gamemode', 'Survival');
        $this->protocol    = (int) $this->get($server, 'protocol', 84);
        $this->version     = $this->get($server, 'version', '0.15.10');
        $this->maxPlayers  = (int) $this->get($server, 'max-players', 20);
        $this->pluginPath  = $this->get($server, 'plugin-path', getcwd() . DIRECTORY_SEPARATOR . 'plugins');

        // auto: proceso aparte en Windows (unica forma de no bloquear el tick
        // loop leyendo la consola) y stdin directo en el resto.
        // Otros valores: inline, process, off.
        $this->consoleMode = $this->get($server, 'console', 'auto');

        $this->tickInterval = 1.0 / 20;
        $this->startTime = microtime(true);

        $this->logger = $this->createLogger($config);
        $this->manager = new ServerManager($this->logger);

        $this->eventManager = EventManager::getInstance();
        $this->eventManager->setLogger($this->logger);
    }

    /**
     * @param array $section
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function get(array $section, $key, $default)
    {
        return isset($section[$key]) ? $section[$key] : $default;
    }

    /**
     * @param array $config
     * @return Logger
     */
    private function createLogger(array $config)
    {
        $levels = array(
            'debug'   => Logger::LEVEL_DEBUG,
            'info'    => Logger::LEVEL_INFO,
            'notice'  => Logger::LEVEL_NOTICE,
            'warning' => Logger::LEVEL_WARNING,
            'error'   => Logger::LEVEL_ERROR,
        );

        $logging = isset($config['logging']) ? $config['logging'] : array();
        $levelName = $this->get($logging, 'level', 'info');
        $level = isset($levels[$levelName]) ? $levels[$levelName] : Logger::LEVEL_INFO;

        return new Logger($level, $this->get($logging, 'file', null));
    }

    // --- Accesores ---

    /** @return string */
    public function getPassword() { return $this->password; }
    /** @return Logger */
    public function getLogger() { return $this->logger; }
    /** @return ServerManager */
    public function getManager() { return $this->manager; }
    /** @return EventManager */
    public function getEventManager() { return $this->eventManager; }
    /** @return CommandMap|null */
    public function getCommandMap() { return $this->commandMap; }
    /** @return Console|null */
    public function getConsole() { return $this->console; }
    /** @return RakLibProxy|null */
    public function getRakProxy() { return $this->rakProxy; }
    /** @return int */
    public function getRakPort() { return $this->rakPort; }
    /** @return string */
    public function getDescription() { return $this->description; }
    /** @return int */
    public function getMaxPlayers() { return $this->maxPlayers; }
    /** @return float segundos */
    public function getUptime() { return microtime(true) - $this->startTime; }
    /** @return bool */
    public function isRunning() { return $this->running; }

    // --- Ciclo de vida ---

    public function start()
    {
        TextFormat::enableWindowsColors();

        $this->commandMap = new CommandMap($this);
        $this->console = new Console($this, $this->commandMap, $this->logger, $this->consoleMode);
        $this->console->printBanner();

        if (!$this->bindSocket()) {
            return;
        }

        $this->logger->info("Proxy TCP escuchando en {$this->bindIp}:{$this->port}");
        $this->logger->info("Server conectado: {$this->maxServers}");

        $this->startRakProxy();
        $this->loadPlugins();

        $this->console->printHelp();
        $this->console->open();

        $this->running = true;

        $startEvent = new ProxyStartEvent($this);
        $startEvent->call();

        $this->mainLoop();
    }

    /** @return bool */
    private function bindSocket()
    {
        $this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            $this->logger->critical('No se pudo crear el socket: ' . socket_strerror(socket_last_error()));
            return false;
        }

        @socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        @socket_set_nonblock($this->socket);

        if (!@socket_bind($this->socket, $this->bindIp, $this->port)) {
            $this->logger->critical(
                "No se pudo abrir {$this->bindIp}:{$this->port}: " . socket_strerror(socket_last_error($this->socket))
            );
            @socket_close($this->socket);
            return false;
        }

        if (!@socket_listen($this->socket, 128)) {
            $this->logger->critical('Fallo en listen(): ' . socket_strerror(socket_last_error($this->socket)));
            @socket_close($this->socket);
            return false;
        }

        return true;
    }

    private function startRakProxy()
    {
        $this->rakProxy = new RakLibProxy(
            $this->manager,
            $this->logger,
            '0.0.0.0',
            $this->rakPort,
            $this->motd,
            $this->subMotd,
            $this->gamemode,
            $this->protocol,
            $this->version,
            $this->maxPlayers
        );

        if ($this->rakProxy->start()) {
            $this->logger->info("Proxy UDP activo en el puerto {$this->rakPort}");
            $this->manager->setRakProxy($this->rakProxy);
        } else {
            $this->logger->warning('El proxy RakLib no arranco: los jugadores no podran conectarse');
            $this->rakProxy = null;
        }
    }

    private function loadPlugins()
    {
        $this->pluginLoader = new PluginLoader($this, $this->logger, $this->pluginPath);
        $loaded = $this->pluginLoader->loadAll();

        if ($loaded > 0) {
            $this->logger->info("{$loaded} listener(s) cargados desde {$this->pluginPath}");
        }
    }

    private function mainLoop()
    {
        $this->logger->info('Proxy iniciado, esperando conexiones...');
        $lastBroadcast = microtime(true);

        while ($this->running) {
            $start = microtime(true);

            try {
                $this->acceptConnections();
                $this->tickConnections();

                if ($this->rakProxy !== null) {
                    $this->rakProxy->tick();
                }

                // Lista de clientes a los backends cada 10s
                if (microtime(true) - $lastBroadcast >= 10) {
                    $this->manager->broadcastClientList();
                    $lastBroadcast = microtime(true);
                }

                if ($this->console !== null) {
                    $this->console->tick();
                }
            } catch (\Throwable $e) {
                $this->logException($e, 'en el hilo principal');
            }

            $elapsed = microtime(true) - $start;
            $sleepTime = $this->tickInterval - $elapsed;
            if ($sleepTime > 0) {
                usleep((int) ($sleepTime * 1000000));
            }
        }

        $this->shutdown();
    }

    private function tickConnections()
    {
        foreach ($this->manager->getServers() as $server) {
            if ($server->isConnected()) {
                $server->tick();
            }
        }

        foreach ($this->pendingConnections as $hash => $connection) {
            if ($connection->isConnected()) {
                $connection->tick();
            } else {
                unset($this->pendingConnections[$hash]);
            }
        }
    }

    private function acceptConnections()
    {
        $read = array($this->socket);
        $write = null;
        $except = null;

        $changed = @socket_select($read, $write, $except, 0);
        if ($changed === false || $changed === 0) {
            return;
        }

        while (true) {
            $client = @socket_accept($this->socket);
            if ($client === false) {
                break;
            }

            $total = count($this->manager->getServers()) + count($this->pendingConnections);
            if ($total >= $this->maxServers) {
                $this->logger->warning('Limite de servidores alcanzado, conexion rechazada');
                @socket_close($client);
                continue;
            }

            @socket_set_nonblock($client);
            @socket_set_option($client, SOL_TCP, TCP_NODELAY, 1);

            $connection = new ClientConnection($client, $this, $this->manager, $this->logger);
            $this->pendingConnections[$connection->getHash()] = $connection;
            $this->logger->info('Server Connection: ' . $connection->getHash() . ' (pendiente de autenticar)');
        }
    }

    /**
     * @param string $hash
     */
    public function removePendingConnection($hash)
    {
        unset($this->pendingConnections[$hash]);
    }

    public function stop()
    {
        $this->running = false;
    }

    public function shutdown()
    {
        $this->logger->info('Apagando Proxy...');

        try {
            $event = new ProxyShutdownEvent($this);
            $event->call();
        } catch (\Throwable $e) {
            $this->logException($e, 'durante ProxyShutdownEvent');
        }

        foreach ($this->manager->getServers() as $server) {
            $server->disconnect('El proxy se esta apagando');
        }

        foreach ($this->pendingConnections as $connection) {
            $connection->disconnect('El proxy se esta apagando');
        }

        if ($this->socket !== null) {
            @socket_close($this->socket);
            $this->socket = null;
        }

        if ($this->rakProxy !== null) {
            $this->rakProxy->shutdown();
        }

        if ($this->console !== null) {
            $this->console->close();
        }

        $this->logger->info('Proxy detenido');
    }

    /**
     * @param \Throwable $e
     * @param string $context
     */
    private function logException($e, $context)
    {
        $this->logger->critical(
            get_class($e) . " sin capturar {$context}: " . $e->getMessage()
            . ' en ' . $e->getFile() . ':' . $e->getLine()
        );
        foreach (explode("\n", $e->getTraceAsString()) as $line) {
            $this->logger->critical('  ' . $line);
        }
    }
}
