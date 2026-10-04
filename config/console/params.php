<?php

declare(strict_types=1);

use App\Console\ApiKeyRegenerateCommand;
use App\Console\BulkWorkCommand;
use App\Console\FcmCheckCommand;
use App\Console\NotificationPurgeCommand;
use App\Console\NotificationWorkCommand;
use App\Console\PushWatchCommand;
use App\Console\SeedCommand;
use App\Console\TwaFingerprintCommand;
use App\Console\WebPushCheckCommand;

return [
    'yiisoft/db-migration' => [
        'newMigrationPath' => dirname(__DIR__, 2) . '/migrations',
        'sourcePaths' => [dirname(__DIR__, 2) . '/migrations'],
        'sourceNamespaces' => [],
    ],
    'yiisoft/yii-console' => [
        'commands' => [
            'app:seed' => SeedCommand::class,
            'app:notification:work' => NotificationWorkCommand::class,
            'app:notification:purge' => NotificationPurgeCommand::class,
            'app:api-key:regenerate' => ApiKeyRegenerateCommand::class,
            'app:fcm:check' => FcmCheckCommand::class,
            'app:webpush:check' => WebPushCheckCommand::class,
            'app:webpush:watch' => PushWatchCommand::class,
            'app:bulk:work' => BulkWorkCommand::class,
            'app:twa:fingerprints' => TwaFingerprintCommand::class,
        ],
    ],
];
