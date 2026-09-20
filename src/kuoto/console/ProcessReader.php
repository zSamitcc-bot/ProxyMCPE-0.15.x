<?php

namespace kuoto\console;

use kuoto\utils\Logger;

class ProcessReader implements ConsoleReader
{
    private $logger;
    private $server = null;
    private $client = null;
    private $process = null;
    private $pipes = array();
    private $buffer = '';
    private $startedAt = 0;
    private $stderrBuffer = '';
    private $failed = false;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->startedAt = microtime(true);
        $this->spawn();
    }

    private function spawn()
    {
        if (!function_exists('proc_open')) {
            $this->fail('proc_open esta deshabilitado');
            return;
        }

        $errno = 0;
        $errstr = '';

        $server = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr
        );

        if ($server === false) {
            $this->fail('no se pudo abrir el socket local: ' . $errstr);
            return;
        }

        @stream_set_blocking($server, false);
        $this->server = $server;

        $name = stream_socket_get_name($server, false);

        if ($name === false) {
            $this->fail('no se pudo obtener el puerto del lector');
            @fclose($server);
            $this->server = null;
            return;
        }

        $pos = strrpos($name, ':');

        if ($pos === false) {
            $this->fail('no se pudo obtener el puerto del lector');
            @fclose($server);
            $this->server = null;
            return;
        }

        $port = (int) substr($name, $pos + 1);

        $binary = defined('PHP_BINARY') && PHP_BINARY !== ''
            ? PHP_BINARY
            : 'php';

        $reader = __DIR__ . DIRECTORY_SEPARATOR . 'reader.php';

        $command = escapeshellarg($binary)
            . ' '
            . escapeshellarg($reader)
            . ' '
            . $port;

        $descriptors = array(
            0 => array('file', 'php://stdin', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        $options = array();

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $options['bypass_shell'] = true;
        }

        $warnings = array();

        set_error_handler(function ($errno, $errstr) use (&$warnings) {
            $warnings[] = $errstr;
            return true;
        });

        $process = proc_open(
            $command,
            $descriptors,
            $this->pipes,
            null,
            null,
            $options
        );

        restore_error_handler();

        if (!is_resource($process)) {
            $detail = empty($warnings)
                ? ''
                : ' -- ' . implode(' | ', $warnings);

            $this->fail(
                'no se pudo lanzar el proceso lector de consola' . $detail
            );

            @fclose($server);
            $this->server = null;
            return;
        }

        $this->process = $process;

        foreach ($this->pipes as $pipe) {
            @stream_set_blocking($pipe, false);
        }
    }

    public function readLine()
{
    $this->drainStderr();
    $this->acceptClient();

    if ($this->client === null) {
        return null;
    }

    $read = array($this->client);
    $write = null;
    $except = null;

    $selected = @stream_select(
        $read,
        $write,
        $except,
        0,
        0
    );

    if ($selected === false || $selected === 0) {
        return null;
    }

    $chunk = @fread($this->client, 8192);

    if (is_string($chunk) && $chunk !== '') {
        $this->buffer .= $chunk;
    } elseif (@feof($this->client)) {
        @fclose($this->client);
        $this->client = null;
        return null;
    }

    $position = strcspn($this->buffer, "\r\n");

    if ($position >= strlen($this->buffer)) {
        return null;
    }

    $line = substr($this->buffer, 0, $position);

    $remaining = substr(
        $this->buffer,
        $position
    );

    $remaining = ltrim(
        $remaining,
        "\r\n"
    );

    $this->buffer = $remaining;

    return $line;
}
    private function drainStderr()
    {
        if (!isset($this->pipes[2]) || !is_resource($this->pipes[2])) {
            return;
        }

        $chunk = @fread($this->pipes[2], 4096);

        if (is_string($chunk) && $chunk !== '') {
            $this->stderrBuffer .= $chunk;
        }
    }

    private function acceptClient()
    {
        if ($this->client !== null || $this->server === null) {
            return;
        }

        $client = @stream_socket_accept($this->server, 0);

        if ($client !== false) {
            @stream_set_blocking($client, false);
            $this->client = $client;
            return;
        }

        if (
            !$this->failed &&
            (microtime(true) - $this->startedAt) > 10
        ) {
            $detail = trim($this->stderrBuffer);

            $this->fail(
                'el proceso lector de consola no respondio'
                . ($detail !== '' ? ' -- ' . $detail : '')
            );
        }
    }

    private function fail($reason)
    {
        $this->failed = true;

        $this->logger->warning(
            'Consola interactiva no disponible: ' . $reason
        );
    }

    public function isOpen()
    {
        return $this->server !== null && !$this->failed;
    }

    public function close()
    {
        if ($this->client !== null) {
            @fclose($this->client);
            $this->client = null;
        }

        if ($this->server !== null) {
            @fclose($this->server);
            $this->server = null;
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        $this->pipes = array();

        if (is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
            $this->process = null;
        }
    }
}