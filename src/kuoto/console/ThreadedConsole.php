<?php

namespace kuoto\console;

class ThreadedConsole extends \Thread
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

    public function shutdown()
    {
        $this->running = false;
    }
}