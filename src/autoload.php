<?php

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
