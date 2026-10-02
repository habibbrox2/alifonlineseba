<?php

declare(strict_types=1);

use App\Notification\Channel\FcmChannel;
use App\Notification\Channel\TelegramChannel;
use App\Notification\NotificationManager;
use App\Notification\QueueRepository;
use App\Notification\TemplateRenderer;
use App\Repository\BotConnectionRepository;
use App\Repository\DeviceRepository;
use App\Service\DeliverableStorage;
use App\Service\ReceiptStorage;

return [
    // `ReceiptStorage` takes a filesystem path, which the container cannot infer,
    // so it is built through its own factory rather than autowired.
    ReceiptStorage::class => static fn (): ReceiptStorage => ReceiptStorage::fromProjectRoot(),

    // Same reason: the deliverable directory lives outside the web root, so the
    // container cannot infer the path.
    DeliverableStorage::class => static fn (): DeliverableStorage => DeliverableStorage::fromProjectRoot(),

    // Notification stack: repositories autowire; channels are plain classes.
    QueueRepository::class => QueueRepository::class,
    DeviceRepository::class => DeviceRepository::class,
    BotConnectionRepository::class => BotConnectionRepository::class,
    TemplateRenderer::class => TemplateRenderer::class,
    NotificationManager::class => NotificationManager::class,
    FcmChannel::class => FcmChannel::class,
    TelegramChannel::class => TelegramChannel::class,
];
