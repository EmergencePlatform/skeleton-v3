<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/php-classes',
        __DIR__.'/php-config',
        __DIR__.'/php-migrations',
        __DIR__.'/event-handlers',
        __DIR__.'/site-root',
        __DIR__.'/site-tasks',
        __DIR__.'/console-commands',
        __DIR__.'/data-exporters',
        __DIR__.'/dwoo-plugins',
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
    ])
    ->setRiskyAllowed(false)
    ->setFinder($finder);
