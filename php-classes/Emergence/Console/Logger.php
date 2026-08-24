<?php

namespace Emergence\Console;

use Colors\Color;
use Emergence\Logger as EmergenceLogger;

class Logger extends \Psr\Log\AbstractLogger
{
    public static $theme = [
        'debug' => 'dark',
        'info' => 'default',
        'notice' => ['default', 'bold'],
        'warning' => 'yellow',
        'error' => 'red',
        'critical' => ['red', 'bold'],
        'alert' => ['white', 'bg_red'],
        'emergency' => ['white', 'bg_red', 'bold']
    ];

    public function log($level, $message, array $context = []): void
    {
        static $c = null;

        // colors.php is an optional console nicety; degrade to plain output
        // when it isn't installed (e.g. composed runtimes without the
        // legacy vendored lib)
        if ($c === null) {
            $c = class_exists(Color::class) ? new Color() : false;

            if ($c) {
                $c->setTheme(static::$theme);
                $c->setForceStyle(true);
            }
        }

        $message = EmergenceLogger::interpolate($message, $context);
        $message = str_replace(PHP_EOL, '⏎', $message);

        echo $c
            ? $c($level.': '.$message)->apply($level).PHP_EOL
            : $level.': '.$message.PHP_EOL;
        flush();
    }
}
