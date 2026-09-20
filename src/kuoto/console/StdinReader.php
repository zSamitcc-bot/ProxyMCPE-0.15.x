<?php

namespace kuoto\console;

/**
 * Lector directo de stdin en modo no bloqueante. Valido en Linux y macOS.
 *
 * No hace echo de lo que se teclea: de eso ya se encarga la terminal (modo
 * canonico). La version anterior lo imprimia ella misma, de ahi que cada
 * comando apareciese duplicado en pantalla ("stats" y debajo "stats").
 */
class StdinReader implements ConsoleReader
{
    /** @var resource|null */
    private $stdin = null;
    /** @var string */
    private $buffer = '';

    public function __construct()
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        $stdin = @fopen('php://stdin', 'r');
        if ($stdin === false) {
            return;
        }

        // Si el modo no bloqueante no se puede activar, es mas seguro no leer
        // nada que arriesgarse a congelar el tick loop.
        if (@stream_set_blocking($stdin, false) !== true) {
            @fclose($stdin);
            return;
        }

        $this->stdin = $stdin;
    }

    public function readLine()
    {
        if ($this->stdin === null) {
            return $this->extractLine();
        }

        $chunk = @fread($this->stdin, 8192);
        if (is_string($chunk) && $chunk !== '') {
            $this->buffer .= $chunk;
        }

        return $this->extractLine();
    }

    /** @return string|null */
    private function extractLine()
    {
        $position = strcspn($this->buffer, "\r\n");
        if ($position >= strlen($this->buffer)) {
            return null; // todavia no hay un salto de linea
        }

        $line = substr($this->buffer, 0, $position);
        $rest = substr($this->buffer, $position);
        $this->buffer = preg_replace('/^\r\n|^[\r\n]/', '', $rest);

        return $line;
    }

    public function isOpen()
    {
        return $this->stdin !== null;
    }

    public function close()
    {
        if ($this->stdin !== null) {
            @fclose($this->stdin);
            $this->stdin = null;
        }
    }
}
