<?php
spl_autoload_register(function ($class) {
    if (strncmp('Zahls\\', $class, 6) !== 0) {
        return;
    }
    $file = __DIR__ . '/zahls/zahls-php/lib/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
