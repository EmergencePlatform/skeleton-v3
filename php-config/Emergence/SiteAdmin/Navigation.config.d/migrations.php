<?php

Emergence\SiteAdmin\Navigation::$items['migrations'] = [
    'label' => 'Migrations',
    'url' => '/site-admin/migrations',
    'after' => 'tasks',
    'badge' => count(array_filter(Emergence\SiteAdmin\MigrationsRequestHandler::getMigrations(), fn($migration) => $migration['status'] == 'new'))
];