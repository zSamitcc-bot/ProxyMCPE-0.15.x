<?php

/**
 * Kuoto Proxy - Central Proxy for Minecraft PE
 *
 * PHP 7.0+ compatible
 * Run: php server.php
 *
 * Estructura de carpetas/namespaces al estilo de la API de PocketMine-MP:
 *   src/kuoto/server/    -> kuoto\server    (SynapseServer, ServerManager)
 *   src/kuoto/network/   -> kuoto\network   (RakLibProxy, ClientConnection)
 *   src/kuoto/console/   -> kuoto\console   (Console)
 *   src/kuoto/utils/     -> kuoto\utils     (Logger)
 *   src/kuoto/protocol/  -> kuoto\protocol  (paquetes: HeartbeatPacket, PlayerLoginPacket, etc.)
 */

use kuoto\server\SynapseServer;

define('BASE_PATH', __DIR__);

require BASE_PATH . '/src/autoload.php';

$configPath = BASE_PATH . '/server.properties';
if (!file_exists($configPath)) {
    echo "Error: server.properties not found!\n";
    exit(1);
}

// Parse server.properties format (key=value)
$lines = file($configPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$raw = array();
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $pos = strpos($line, '=');
    if ($pos === false) {
        continue;
    }
    $key = trim(substr($line, 0, $pos));
    $value = trim(substr($line, $pos + 1));
    $raw[$key] = $value;
}

// Convert flat properties to nested config array
$config = array(
    'server' => array(
        'bind-ip'      => isset($raw['server.bind-ip']) ? $raw['server.bind-ip'] : '0.0.0.0',
        'port'         => isset($raw['server.port']) ? (int) $raw['server.port'] : 10305,
        'password'     => isset($raw['server.password']) ? $raw['server.password'] : '123456',
        'max-servers'  => isset($raw['server.max-servers']) ? (int) $raw['server.max-servers'] : 50,
        'description'  => isset($raw['server.description']) ? $raw['server.description'] : 'Kuoto Central Server',
        'rak-port'     => isset($raw['server.rak-port']) ? (int) $raw['server.rak-port'] : 19132,
        'motd'         => isset($raw['server.motd']) ? $raw['server.motd'] : 'Kuoto Proxy',
        'sub-motd'     => isset($raw['server.sub-motd']) ? $raw['server.sub-motd'] : 'A Minecraft PE Proxy',
        'gamemode'     => isset($raw['server.gamemode']) ? $raw['server.gamemode'] : 'Survival',
        'protocol'     => isset($raw['server.protocol']) ? (int) $raw['server.protocol'] : 84,
        'version'      => isset($raw['server.version']) ? $raw['server.version'] : '0.15.10',
        'max-players'  => isset($raw['server.max-players']) ? (int) $raw['server.max-players'] : 20,
    ),
    'console' => array(
        'enabled' => isset($raw['console.enabled']) ? ($raw['console.enabled'] === 'true') : true,
    ),
    'logging' => array(
        'level' => isset($raw['logging.level']) ? $raw['logging.level'] : 'info',
        'file'  => isset($raw['logging.file']) ? $raw['logging.file'] : null,
    ),
);

// Check required extensions
$required = array('sockets', 'json');
$missing = array();
foreach ($required as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
if (!empty($missing)) {
    echo "Warning: Missing PHP extensions: " . implode(', ', $missing) . "\n";
}

// Handle signals - only if pcntl extension is available
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_signal(SIGINT, function () use (&$server) {
        echo "\nReceived SIGINT, shutting down...\n";
        if (isset($server)) {
            $server->stop();
        }
        exit(0);
    });
    pcntl_signal(SIGTERM, function () use (&$server) {
        echo "\nReceived SIGTERM, shutting down...\n";
        if (isset($server)) {
            $server->stop();
        }
        exit(0);
    });
    pcntl_async_signals(true);
}

$server = new SynapseServer($config);
$server->start();
