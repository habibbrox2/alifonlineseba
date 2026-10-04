<?php

declare(strict_types=1);

use App\Notification\Channel\FcmChannel;
use App\Notification\Channel\TelegramChannel;
use App\Notification\Channel\WebPushChannel;
use App\Notification\NotificationManager;
use App\Notification\QueueRepository;
use App\Notification\TemplateRenderer;
use App\Repository\AppReleaseRepository;
use App\Repository\BotConnectionRepository;
use App\Repository\DeviceRepository;
use App\Service\AppReleaseService;
use App\Repository\SettingsRepository;
use App\Service\DeliverableStorage;
use App\Service\OrderWindowService;
use App\Service\ReceiptStorage;
use App\Service\TwaAssetLinks;

return [
    // `ReceiptStorage` takes a filesystem path, which the container cannot infer,
    // so it is built through its own factory rather than autowired.
    ReceiptStorage::class => static fn (): ReceiptStorage => ReceiptStorage::fromProjectRoot(),

    // Same reason: the deliverable directory lives outside the web root, so the
    // container cannot infer the path.
    DeliverableStorage::class => static fn (): DeliverableStorage => DeliverableStorage::fromProjectRoot(),

    // And the same again for self-hosted APKs: `web/releases/` sits outside
    // the document root, so the container cannot infer the path. The
    // repository autowires.
    AppReleaseRepository::class => AppReleaseRepository::class,
    AppReleaseService::class => static fn (AppReleaseRepository $r): AppReleaseService => AppReleaseService::fromProjectRoot($r),

    // The TWA association document is two environment values — the canonical
    // origin and the app's signing certificates — so there is nothing for the
    // container to infer. Built from the environment, exactly as
    // AppReleaseService reads its own configuration.
    TwaAssetLinks::class => static fn (): TwaAssetLinks => TwaAssetLinks::fromEnv(),

    // The order window is three rows in `site_setting`, so there is no
    // constructor argument the container could infer. The repository autowires;
    // the service reads the rows itself.
    OrderWindowService::class => static fn (SettingsRepository $s): OrderWindowService => OrderWindowService::fromSettings($s),

    // Notification stack: repositories autowire; channels are plain classes.
    QueueRepository::class => QueueRepository::class,
    DeviceRepository::class => DeviceRepository::class,
    BotConnectionRepository::class => BotConnectionRepository::class,
    TemplateRenderer::class => TemplateRenderer::class,
    NotificationManager::class => NotificationManager::class,
    FcmChannel::class => FcmChannel::class,
    TelegramChannel::class => TelegramChannel::class,
    // Web Push reaches the browsers that never install the app — including
    // every admin, who works from a browser rather than from the APK. It is a
    // plain class with two repository dependencies, so the container would
    // autowire it; it is listed for the same reason as the two above, so the
    // set of notification channels is one readable list.
    WebPushChannel::class => WebPushChannel::class,
];
