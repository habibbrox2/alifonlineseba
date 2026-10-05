<?php

declare(strict_types=1);

use App\Console\ApiKeyRegenerateCommand;
use App\Console\BulkWorkCommand;
use App\Console\FcmCheckCommand;
use App\Console\NotificationPurgeCommand;
use App\Console\NotificationWorkCommand;
use App\Console\OrderSeedCommand;
use App\Console\PushWatchCommand;
use App\Console\SeedCommand;
use App\Console\StaffCommand;
use App\Console\SuperAdminCommand;
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
            // The other half of app:seed: the catalog and the accounts without
            // a queue, so /service-history, /admin/orders and the dashboard
            // counters all read as broken rather than new.
            'app:seed-orders' => OrderSeedCommand::class,
            // The bootstrap for platform authority: /admin/staff is itself
            // super-admin-only, so the first one has to be named from the CLI.
            'app:super-admin' => SuperAdminCommand::class,
            // /admin/staff promotes an account but cannot create one, and
            // app:seed skips every user on a database that already has rows.
            'app:staff' => StaffCommand::class,
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
