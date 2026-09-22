<?php

namespace kuoto\console;

class ThreadedConsole extends \Thread implements ConsoleReader
{
    private $lines;
    private $running = true;

    public function __construct()
    {
        $this->lines = new \Volatile();
    }

    public function run()
    {
        $stdin = @fopen('php://stdin', 'r');

        if ($stdin === false) {
            return;
        }

        while ($this->running) {
            $line = fgets($stdin);

            if ($line === false) {
                usleep(10000);
                continue;
            }

            $line = rtrim($line, "\r\n");

            if ($line !== '') {
                $this->lines[] = $line;
            }
        }

        @fclose($stdin);
    }

    public function hasLine()
    {
        return count($this->lines) > 0;
    }

    public function getLine()
    {
        if (count($this->lines) === 0) {
            return null;
        }

        return $this->lines->shift();
    }

    /**
     * ConsoleReader::readLine() -- delega en getLine() para que Console
     * pueda tratar cualquier lector (con o sin pthreads) de forma uniforme.
     */
    public function readLine()
    {
        return $this->getLine();
    }

    /** ConsoleReader::isOpen() */
    public function isOpen()
    {
        return $this->running;
    }

    public function shutdown()
    {
        $this->running = false;
    }

    /** ConsoleReader::close() */
    public function close()
    {
        $this->shutdown();
    }
}