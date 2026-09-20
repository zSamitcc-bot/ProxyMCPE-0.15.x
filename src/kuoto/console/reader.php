<?php

$port = isset($argv[1]) ? (int) $argv[1] : 0;

if ($port <= 0) {
    fwrite(STDERR, "uso: reader.php <puerto>\n");
    exit(1);
}

$errno = 0;
$errstr = '';

$socket = @stream_socket_client(
    'tcp://127.0.0.1:' . $port,
    $errno,
    $errstr,
    5
);

if ($socket === false) {
    fwrite(
        STDERR,
        "reader: no se pudo conectar al proxy: " . $errstr . "\n"
    );
    exit(1);
}

@stream_set_blocking($socket, true);
@stream_set_write_buffer($socket, 0);

$stdin = @fopen('php://stdin', 'r');

if ($stdin === false) {
    @fclose($socket);
    exit(1);
}

while (($line = fgets($stdin)) !== false) {
    $line = rtrim($line, "\r\n");

    if ($line === '') {
        continue;
    }

    $data = $line . "\n";
    $length = strlen($data);
    $offset = 0;

    while ($offset < $length) {
        $written = @fwrite(
            $socket,
            substr($data, $offset)
        );

        if ($written === false || $written === 0) {
            break 2;
        }

        $offset += $written;
    }

    @fflush($socket);
}

@fclose($stdin);
@fclose($socket);

exit(0);