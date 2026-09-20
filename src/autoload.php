<?php

/**
 * Autocargador ligero para Kuoto Proxy.
 *
 * Reemplaza a vendor/autoload.php (generado por Composer). El proyecto no
 * tiene dependencias externas reales, asi que Composer solo se usaba para
 * mapear los namespaces kuoto\* a sus carpetas.
 *
 * Antes habia que anadir a mano una entrada por cada subnamespace; al crecer
 * el proyecto (event, command, plugin, ...) eso se convertia en una fuente
 * segura de errores del tipo "class not found". Ahora es un PSR-4 generico:
 *
 *   kuoto\event\player\PlayerLoginEvent -> src/kuoto/event/player/PlayerLoginEvent.php
 */

spl_autoload_register(function ($class) {
    $prefix = 'kuoto\\';
    $baseDir = __DIR__ . '/kuoto/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
