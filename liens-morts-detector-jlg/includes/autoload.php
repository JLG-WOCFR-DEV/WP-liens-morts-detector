<?php

spl_autoload_register(
    static function ($class) {
        $prefix = 'JLG\\BrokenLinks\\';
        $prefix_length = strlen($prefix);

        if (strncmp($class, $prefix, $prefix_length) !== 0) {
            return;
        }

        $relative_class = substr($class, $prefix_length);
        $relative_path  = str_replace('\\', DIRECTORY_SEPARATOR, $relative_class) . '.php';
        $file           = __DIR__ . DIRECTORY_SEPARATOR . $relative_path;

        if (is_readable($file)) {
            require_once $file;
        }
    }
);
