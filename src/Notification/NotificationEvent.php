<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * The events this application actually emits — one per real state transition
 * in ServiceManager / TopupService. Everything else from the brief's generic
 * list does not exist here and is deliberately absent.
 *
 * Each event carries the channel matrix and the template copy so the call
 * sites stay one-liners.
 */
final class NotificationEvent
{
    public const USER_REGISTERED = 'user.registered';
    public const SERVICE_REQUEST_CREATED = 'service_request.created';
    public const SERVICE_REQUEST_PROCESSING = 'service_request.processing';
    public const SERVICE_REQUEST_COMPLETED = 'service_request.completed';
    public const SERVICE_REQUEST_FAILED = 'service_request.failed';
    public const SERVICE_REQUEST_CANCELLED = 'service_request.cancelled';
    public const SERVICE_REQUEST_FILE = 'service_request.file';
    public const TOPUP_REQUESTED = 'topup.requested';
    public const TOPUP_APPROVED = 'topup.approved';
    public const TOPUP_REJECTED = 'topup.rejected';
    public const TOPUP_CANCELLED = 'topup.cancelled';
    public const REFERRAL_BONUS_REFERRER = 'referral.bonus_referrer';
    public const REFERRAL_BONUS_REFEREE = 'referral.bonus_referee';
    public const REFERRAL_REJECTED = 'referral.rejected';
    public const ADMIN_WITHDRAW_REQUESTED = 'admin_withdraw.requested';
    public const ADMIN_WITHDRAW_APPROVED = 'admin_withdraw.approved';
    public const ADMIN_WITHDRAW_REJECTED = 'admin_withdraw.rejected';
    public const SYSTEM_ALERT = 'system.alert';

    /**
     * Per-event channel matrix + priority + admin fan-out.
     *
     * Rules from the audit: telegram is admins-only, push is off for
     * topup.requested on the user side, in-app is always on.
     *
     * ## Why `webpush` sits next to `fcm` everywhere
     *
     * The two channels have the same audience and different clients: `fcm`
     * reaches the Android app, `webpush` reaches the browser. Somebody who only
     * ever uses the site has to hear about their order finishing exactly as
     * loudly as one who installed the APK — and, this is the part that is easy
     * to miss, an *admin* never installs the app. Staff work from a browser, all
     * day, on whatever machines they happen to have. An admin-alert channel list
     * with no `webpush` in it means a new recharge sits unseen until somebody
     * happens to open the panel, which is the failure this whole feature exists
     * to remove. That is why the `also_admins`-only events carry `webpush` too:
     * the recipient list is staff, and staff are on the web.
     *
     * @return array{channels: string[], priority: int, also_admins: bool}
     */
    public static function spec(string $event): array
    {
        return match ($event) {
            self::USER_REGISTERED => ['channels' => ['in_app'], 'priority' => 5, 'also_admins' => false],
            self::SERVICE_REQUEST_CREATED => ['channels' => ['in_app', 'fcm', 'webpush', 'telegram'], 'priority' => 2, 'also_admins' => true],
            self::SERVICE_REQUEST_PROCESSING => ['channels' => ['in_app', 'fcm', 'webpush'], 'priority' => 4, 'also_admins' => false],
            self::SERVICE_REQUEST_COMPLETED => ['channels' => ['in_app', 'fcm', 'webpush', 'whatsapp'], 'priority' => 2, 'also_admins' => false],
            self::SERVICE_REQUEST_FAILED => ['channels' => ['in_app', 'fcm', 'webpush'], 'priority' => 2, 'also_admins' => false],
            self::SERVICE_REQUEST_CANCELLED => ['channels' => ['in_app', 'fcm', 'webpush'], 'priority' => 4, 'also_admins' => false],
            // The result file is the payoff of the whole order. Pushed, not
            // just filed, because the user cannot know it arrived without
            // opening the app — and a silent inbox entry for the thing they
            // paid for is the definition of a support ticket.
            self::SERVICE_REQUEST_FILE => ['channels' => ['in_app', 'fcm', 'webpush'], 'priority' => 1, 'also_admins' => false],
            // Admins learn a payment is waiting; the user does not need a push
            // for having just made one.
            self::TOPUP_REQUESTED => ['channels' => ['in_app', 'webpush', 'telegram'], 'priority' => 1, 'also_admins' => true],
            self::TOPUP_APPROVED => ['channels' => ['in_app', 'fcm', 'webpush', 'whatsapp'], 'priority' => 2, 'also_admins' => false],
            self::TOPUP_REJECTED => ['channels' => ['in_app', 'fcm', 'webpush', 'whatsapp'], 'priority' => 2, 'also_admins' => false],
            self::TOPUP_CANCELLED => ['channels' => ['in_app', 'webpush'], 'priority' => 4, 'also_admins' => true],
            // Money arriving is the single most re-opened page in the app, so
            // the referrer bonus is pushed, not just filed in the inbox. The
            // new user's own welcome bonus rides WhatsApp because that is the
            // channel they were already expecting to hear from us on.
            self::REFERRAL_BONUS_REFERRER => ['channels' => ['in_app', 'fcm', 'webpush', 'whatsapp'], 'priority' => 1, 'also_admins' => false],
            self::REFERRAL_BONUS_REFEREE => ['channels' => ['in_app', 'fcm', 'webpush', 'whatsapp'], 'priority' => 1, 'also_admins' => false],
            self::REFERRAL_REJECTED => ['channels' => ['in_app'], 'priority' => 3, 'also_admins' => false],
            // A withdrawal is money leaving the building, so the queue that
            // decides it has to be loud in every channel staff actually use —
            // and the requester hears the outcome on all of theirs, because
            // the alternative is an admin who submitted a payout and then had
            // to ask whether anyone had seen it.
            self::ADMIN_WITHDRAW_REQUESTED => ['channels' => ['in_app', 'webpush', 'telegram'], 'priority' => 1, 'also_admins' => true],
            self::ADMIN_WITHDRAW_APPROVED => ['channels' => ['in_app', 'webpush'], 'priority' => 1, 'also_admins' => false],
            self::ADMIN_WITHDRAW_REJECTED => ['channels' => ['in_app', 'webpush'], 'priority' => 1, 'also_admins' => false],
            self::SYSTEM_ALERT => ['channels' => ['in_app', 'webpush', 'telegram'], 'priority' => 1, 'also_admins' => true],
            default => ['channels' => ['in_app'], 'priority' => 5, 'also_admins' => false],
        };
    }
}
