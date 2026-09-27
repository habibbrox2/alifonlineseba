<?php

declare(strict_types=1);

use App\Console\SeedCommand;

return [
    'yiisoft/db-migration' => [
        'newMigrationPath' => dirname(__DIR__, 2) . '/migrations',
        'sourcePaths' => [dirname(__DIR__, 2) . '/migrations'],
        'sourceNamespaces' => [],
    ],
    'yiisoft/yii-console' => [
        'commands' => [
            'app:seed' => SeedCommand::class,
        ],
    ],
];
