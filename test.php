<?php

echo "Esperando entrada...\n";

$stdin = fopen("php://stdin", "r");

while (($line = fgets($stdin)) !== false) {
    echo "RECIBIDO: " . rtrim($line, "\r\n") . "\n";
}