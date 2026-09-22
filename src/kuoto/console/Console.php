<?php

namespace kuoto\console;

use kuoto\command\CommandMap;
use kuoto\server\SynapseServer;
use kuoto\utils\Logger;
use kuoto\utils\TextFormat;

class Console
{
    const MAX_LINES_PER_TICK = 16;

    private $proxy;
    private $logger;
    private $commandMap;
    private $reader = null;
    private $mode;

    public function __construct(
        SynapseServer $proxy,
        CommandMap $commandMap,
        Logger $logger,
        $mode = 'auto'
    ) {
        $this->proxy = $proxy;
        $this->commandMap = $commandMap;
        $this->logger = $logger;
        $this->mode = strtolower($mode);
    }

    public function open()
    {
        if ($this->mode === 'off') {
            $this->logger->info(
                'Consola interactiva desactivada por configuracion'
            );
            return;
        }

        $this->reader = $this->createReader($this->mode);

        if ($this->reader === null) {
            $this->logger->warning(
                'No se pudo iniciar ningun lector de consola; '
                . 'la consola interactiva queda desactivada.'
            );
            return;
        }

        $this->logger->setPromptCallback(
            array($this, 'writePrompt')
        );

        $this->logger->setHasPrompt(true);

        $this->writePrompt();
    }

    /**
     * Elige e inicializa un ConsoleReader segun el modo pedido.
     *
     * "threaded" (\Thread/pthreads) es el mas liviano para el tick loop
     * porque lee en un hilo aparte, pero requiere la extension pthreads --
     * ausente en muchos entornos de hosting (Pterodactyl incluido), donde
     * ni siquiera se puede REQUERIR el archivo ThreadedConsole.php sin que
     * PHP tire un Fatal Error al no encontrar la clase padre \Thread. Por
     * eso el chequeo de extension_loaded() va ANTES de tocar esa clase, y
     * el intento de instanciarla igual queda blindado con try/catch: desde
     * PHP 7 esa falta de clase se lanza como \Error, que es capturable.
     *
     * En modo "auto" se prueba threaded (si esta disponible) y se cae a
     * stdin y despues a process; en un modo explicito solo se prueba ese
     * lector y, si falla, se registra el motivo sin intentar otro.
     */
    private function createReader($mode)
    {
        $tryThreaded = $mode === 'auto' || $mode === 'threaded';
        $tryStdin = $mode === 'auto' || $mode === 'stdin';
        $tryProcess = $mode === 'auto' || $mode === 'process';

        if ($tryThreaded) {
            if (extension_loaded('pthreads')) {
                try {
                    $threaded = new ThreadedConsole();
                    $threaded->start();
                    return $threaded;
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'No se pudo iniciar la consola en hilo aparte: '
                        . $e->getMessage()
                    );
                }
            } elseif ($mode === 'threaded') {
                $this->logger->warning(
                    'console: threaded pedido pero la extension pthreads '
                    . 'no esta cargada en este PHP'
                );
            }

            if ($mode === 'threaded') {
                return null;
            }
        }

        if ($tryStdin) {
            $stdin = new StdinReader();
            if ($stdin->isOpen()) {
                return $stdin;
            }

            if ($mode === 'stdin') {
                $this->logger->warning(
                    'console: stdin pedido pero no se pudo abrir stdin '
                    . 'en modo no bloqueante'
                );
                return null;
            }
        }

        if ($tryProcess) {
            $process = new ProcessReader($this->logger);
            if ($process->isOpen()) {
                return $process;
            }
        }

        return null;
    }

    public function tick()
    {
        if ($this->reader === null) {
            return;
        }

        for ($i = 0; $i < self::MAX_LINES_PER_TICK; $i++) {
            $line = $this->reader->readLine();

            if ($line === null) {
                return;
            }

            $line = trim($line);

            $this->logger->setHasPrompt(false);

            if ($line !== '') {
                $this->commandMap->dispatch($line);
            }

            $this->logger->setHasPrompt(true);
            $this->writePrompt();
        }
    }

    public function writePrompt()
    {
        echo TextFormat::GRAY . '> ' . TextFormat::RESET;

        @ob_flush();
        @flush();
    }

    public function printBanner()
    {
        $c = TextFormat::RESET;
        $b = TextFormat::BOLD;
        $aqua = TextFormat::AQUA;
        $gray = TextFormat::GRAY;
        $darkGray = TextFormat::DARK_GRAY;

        echo "\n";
        echo "{$b}{$aqua} _  __          _         {$c}\n";
        echo "{$b}{$aqua}| |/ /_  _  ___| |_ ___   {$c}\n";
        echo "{$b}{$aqua}| ' <| || |(_-<  _/ -_)  {$c}\n";
        echo "{$b}{$aqua}|_|\\_\\\\_,_|/__/\\__\\___|  {$c}\n";
        echo $darkGray . str_repeat('-', 52) . $c . "\n";
        echo "{$gray}  Kuoto Proxy v1.1.3{$c}\n";
        echo "{$gray}  Minecraft PE Proxy{$c}\n";
        echo $darkGray . str_repeat('-', 52) . $c . "\n\n";
    }

    public function printHelp()
    {
        $help = $this->commandMap->getCommand('help');

        if ($help !== null) {
            $help->execute('');
        }
    }

    public function close()
    {
        if ($this->reader !== null) {
            $this->reader->close();
            $this->reader = null;
        }

        $this->logger->setHasPrompt(false);
    }
}