<?php
declare(strict_types=1);

namespace TypechoPlugin\RenewLocation;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Loader
{
    private static bool $registered = false;

    public static function registerCzdbAutoload(): void
    {
        if (self::$registered) {
            return;
        }

        spl_autoload_register(static function (string $class): void {
            if (strpos($class, 'Czdb\\') !== 0) {
                return;
            }

            $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 5));
            $file = __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Czdb'
                . DIRECTORY_SEPARATOR . $relative . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        });

        self::$registered = true;
    }
}
