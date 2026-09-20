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

        $this->reader = new ThreadedConsole();
        $this->reader->start();

        $this->logger->setPromptCallback(
            array($this, 'writePrompt')
        );

        $this->logger->setHasPrompt(true);

        $this->writePrompt();
    }

    public function tick()
    {
        if ($this->reader === null) {
            return;
        }

        for ($i = 0; $i < self::MAX_LINES_PER_TICK; $i++) {
            if (!$this->reader->hasLine()) {
                return;
            }

            $line = $this->reader->getLine();

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
            $this->reader->shutdown();
            $this->reader = null;
        }

        $this->logger->setHasPrompt(false);
    }
}