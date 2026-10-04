<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * The seed copy (bn) for every event/channel pair, one place instead of
 * scattered literals. The database template table is the editable source; this
 * class is the compiled-in fallback that keeps notifications working even if
 * the table is empty.
 *
 * Placeholders: {username}, {amount}, {reference}, {reason}, {service},
 * {referee}, {referrer}.
 */
final class MessageTemplates
{
    /**
     * @return array<string, array{title: string, body: string}> keyed by channel
     */
    public static function forEvent(string $event): array
    {
        return match ($event) {
            NotificationEvent::USER_REGISTERED => [
                'in_app' => [
                    'title' => 'স্বাগতম!',
                    'body' => '{username}, All Seba-এ আপনাকে স্বাগতম। এখন সার্ভিস অর্ডার করতে পারবেন।',
                ],
            ],
            NotificationEvent::SERVICE_REQUEST_CREATED => [
                'in_app' => [
                    'title' => 'অনুরোধ গৃহীত হয়েছে',
                    'body' => '{service} — রেফারেন্স: {reference}',
                ],
                'fcm' => [
                    'title' => 'অনুরোধ গৃহীত',
                    'body' => '{service} অনুরোধ গৃহীত হয়েছে ({reference})।',
                ],
                'telegram' => [
                    'title' => 'নতুন সার্ভিস অনুরোধ',
                    'body' => '{service} — {reference} — ব্যবহারকারী #{user_id}',
                ],
            ],
            NotificationEvent::SERVICE_REQUEST_PROCESSING => [
                'in_app' => ['title' => 'প্রসেসিং চলছে', 'body' => '{service} — রেফারেন্স: {reference}'],
                'fcm' => ['title' => 'প্রসেসিং চলছে', 'body' => '{service} প্রসেস হচ্ছে ({reference})।'],
            ],
            NotificationEvent::SERVICE_REQUEST_COMPLETED => [
                'in_app' => ['title' => 'সার্ভিস সম্পন্ন হয়েছে', 'body' => '{service} — রেফারেন্স: {reference}'],
                'fcm' => ['title' => 'সার্ভিস সম্পন্ন', 'body' => '{service} সম্পন্ন হয়েছে ({reference})।'],
                'whatsapp' => [
                    'title' => 'service_completed',
                    'body' => 'আপনার {service} অনুরোধ সম্পন্ন হয়েছে। রেফারেন্স: {reference}',
                ],
            ],
            NotificationEvent::SERVICE_REQUEST_FAILED => [
                'in_app' => ['title' => 'সার্ভিস ব্যর্থ হয়েছে', 'body' => 'রেফারেন্স: {reference} — {reason}'],
                'fcm' => ['title' => 'সার্ভিস ব্যর্থ', 'body' => '{reference} ব্যর্থ হয়েছে: {reason}'],
            ],
            NotificationEvent::SERVICE_REQUEST_CANCELLED => [
                'in_app' => ['title' => 'অনুরোধ বাতিল হয়েছে', 'body' => 'রেফারেন্স: {reference} — টাকা ফেরত দেওয়া হয়েছে।'],
                'fcm' => ['title' => 'অনুরোধ বাতিল', 'body' => '{reference} বাতিল করা হয়েছে, টাকা ফেরত পাবেন।'],
            ],
            // The file is the deliverable, so the copy says exactly that rather
            // than "completed" — the status may still be প্রসেসিং, and telling
            // the user their request is done when it is not is how a support
            // ticket starts.
            NotificationEvent::SERVICE_REQUEST_FILE => [
                'in_app' => ['title' => 'ফাইল প্রস্তুত', 'body' => '{service} — রেফারেন্স: {reference}। সার্ভিস হিস্ট্রি থেকে ফাইলটি ডাউনলোড করুন।'],
                'fcm' => ['title' => 'ফাইল প্রস্তুত', 'body' => '{service} এর ফাইলটি প্রস্তুত ({reference})। সার্ভিস হিস্ট্রি থেকে ডাউনলোড করুন।'],
            ],
            NotificationEvent::TOPUP_REQUESTED => [
                'in_app' => ['title' => 'রিচার্জ অনুরোধ জমা হয়েছে', 'body' => '{amount} রিচার্জ অনুরোধ জমা হয়েছে — যাচাইয়ের অপেক্ষায়।'],
                'telegram' => [
                    'title' => 'নতুন রিচার্জ অনুরোধ',
                    'body' => '{amount} — {method} — TrxID {reference} — ব্যবহারকারী #{user_id}',
                ],
            ],
            NotificationEvent::TOPUP_APPROVED => [
                'in_app' => [
                    'title' => 'রিচার্জ অনুমোদিত — ব্যালেন্স যোগ হয়েছে',
                    'body' => 'আপনার {amount} রিচার্জ অনুমোদিত হয়েছে। মেথড: {method}, TrxID: {reference}। ব্যালেন্সে টাকা যোগ করা হয়েছে।',
                ],
                'fcm' => ['title' => 'রিচার্জ অনুমোদিত', 'body' => '{amount} ব্যালেন্সে যোগ হয়েছে।'],
                'whatsapp' => [
                    'title' => 'topup_approved',
                    'body' => 'আপনার {amount} রিচার্জ অনুমোদিত হয়েছে (TrxID: {reference})।',
                ],
            ],
            NotificationEvent::TOPUP_REJECTED => [
                'in_app' => [
                    'title' => 'রিচার্জ অনুরোধ বাতিল হয়েছে',
                    'body' => 'আপনার {amount} রিচার্জ অনুরোধ (TrxID {reference}) বাতিল হয়েছে। কারণ: {reason}',
                ],
                'fcm' => ['title' => 'রিচার্জ বাতিল', 'body' => '{amount} রিচার্জ বাতিল হয়েছে: {reason}'],
                'whatsapp' => [
                    'title' => 'topup_rejected',
                    'body' => 'আপনার {amount} রিচার্জ অনুরোধ বাতিল হয়েছে। কারণ: {reason}',
                ],
            ],
            NotificationEvent::TOPUP_CANCELLED => [
                'in_app' => ['title' => 'রিচার্জ বাতিল', 'body' => 'আপনার রিচার্জ অনুরোধ বাতিল করা হয়েছে।'],
                'telegram' => ['title' => 'রিচার্জ বাতিল', 'body' => 'ব্যবহারকারী #{user_id} নিজেই রিচার্জ বাতিল করেছেন।'],
            ],
            NotificationEvent::REFERRAL_BONUS_REFERRER => [
                'in_app' => [
                    'title' => '🎉 রেফারেল বোনাস পেয়েছেন!',
                    'body' => '{referee} আপনার রেফারেলে যোগ দিয়েছেন। ৳{amount} বোনাস আপনার ব্যালেন্সে যোগ করা হয়েছে।',
                ],
                'fcm' => [
                    'title' => 'রেফারেল বোনাস পেয়েছেন',
                    'body' => '{referee} যোগ দিয়েছেন — ৳{amount} ব্যালেন্সে যোগ হয়েছে।',
                ],
                'whatsapp' => [
                    'title' => 'referral_bonus_referrer',
                    'body' => 'আপনার বন্ধু {referee} রেফারেল বোনাস হিসেবে ৳{amount} পেয়েছেন। বিস্তারিত: /referrals',
                ],
            ],
            NotificationEvent::REFERRAL_BONUS_REFEREE => [
                'in_app' => [
                    'title' => '🎁 রেফারেল বোনাস যোগ হয়েছে',
                    'body' => '{referrer} আপনাকে রেফার করেছেন — স্বাগতম বোনাস ৳{amount} আপনার ব্যালেন্সে যোগ করা হয়েছে।',
                ],
                'fcm' => [
                    'title' => 'বোনাস যোগ হয়েছে',
                    'body' => 'স্বাগতম বোনাস ৳{amount} ব্যালেন্সে যোগ হয়েছে।',
                ],
                'whatsapp' => [
                    'title' => 'referral_bonus_referee',
                    'body' => 'স্বাগতম! ৳{amount} বোনাস আপনার ব্যালেন্সে যোগ করা হয়েছে।',
                ],
            ],
            NotificationEvent::REFERRAL_REJECTED => [
                'in_app' => [
                    'title' => 'রেফারেল বাতিল হয়েছে',
                    'body' => '{referee}-এর রেফারেল বাতিল হয়েছে। কারণ: {reason}',
                ],
            ],
            // Withdrawal copy names the amount on both sides of the decision,
            // because the admin's question after submitting is always "where is
            // my money" and an unqualified "approved" does not answer it.
            NotificationEvent::ADMIN_WITHDRAW_REQUESTED => [
                'in_app' => ['title' => 'উত্তোলন অনুরোধ জমা হয়েছে', 'body' => '৳{amount} উত্তোলন অনুরোধ — অনুমোদনের অপেক্ষায়।'],
                'telegram' => ['title' => 'নতুন উত্তোলন অনুরোধ', 'body' => '{amount} — অ্যাডমিন #{user_id} — অনুমোদনের অপেক্ষায়'],
            ],
            // These two have no fcm copy to borrow, because their only
            // recipient is an admin — and admins work in a browser, not on the
            // APK. They carry their own webpush wording rather than falling
            // through to the in-app text.
            NotificationEvent::ADMIN_WITHDRAW_APPROVED => [
                'in_app' => ['title' => 'উত্তোলন অনুমোদিত', 'body' => '৳{amount} উত্তোলন অনুমোদিত হয়েছে। পাঠানোর পর জানানো হবে।'],
                'webpush' => ['title' => 'উত্তোলন অনুমোদিত', 'body' => '৳{amount} উত্তোলন অনুমোদিত হয়েছে।'],
            ],
            NotificationEvent::ADMIN_WITHDRAW_REJECTED => [
                'in_app' => ['title' => 'উত্তোলন বাতিল', 'body' => '৳{amount} উত্তোলন বাতিল হয়েছে। কারণ: {reason}'],
                'webpush' => ['title' => 'উত্তোলন বাতিল', 'body' => '৳{amount} উত্তোলন বাতিল হয়েছে। কারণ: {reason}'],
            ],
            NotificationEvent::SYSTEM_ALERT => [
                'in_app' => ['title' => 'সিস্টেম নোটিশ', 'body' => '{reason}'],
                'telegram' => ['title' => 'সিস্টেম অ্যালার্ট', 'body' => '{reason}'],
            ],
            default => [
                'in_app' => ['title' => 'নোটিফিকেশন', 'body' => '{reason}'],
            ],
        };
    }

    /** @return array<string, array{title: string, body: string, external_id: string|null}> */
    public static function seedRows(): array
    {
        $rows = [];
        foreach ([
            NotificationEvent::USER_REGISTERED,
            NotificationEvent::SERVICE_REQUEST_CREATED,
            NotificationEvent::SERVICE_REQUEST_PROCESSING,
            NotificationEvent::SERVICE_REQUEST_COMPLETED,
            NotificationEvent::SERVICE_REQUEST_FAILED,
            NotificationEvent::SERVICE_REQUEST_CANCELLED,
            NotificationEvent::SERVICE_REQUEST_FILE,
            NotificationEvent::TOPUP_REQUESTED,
            NotificationEvent::TOPUP_APPROVED,
            NotificationEvent::TOPUP_REJECTED,
            NotificationEvent::TOPUP_CANCELLED,
            NotificationEvent::REFERRAL_BONUS_REFERRER,
            NotificationEvent::REFERRAL_BONUS_REFEREE,
            NotificationEvent::REFERRAL_REJECTED,
            NotificationEvent::ADMIN_WITHDRAW_REQUESTED,
            NotificationEvent::ADMIN_WITHDRAW_APPROVED,
            NotificationEvent::ADMIN_WITHDRAW_REJECTED,
            NotificationEvent::SYSTEM_ALERT,
        ] as $event) {
            foreach (self::forEvent($event) as $channel => $template) {
                $rows[] = [
                    'event' => $event,
                    'channel' => $channel,
                    'locale' => 'bn',
                    'title' => $template['title'],
                    'body' => $template['body'],
                    // WhatsApp templates need Meta approval before use; the
                    // name is the integration point once approved.
                    'external_id' => $channel === 'whatsapp' ? $template['title'] : null,
                ];
            }
        }
        return $rows;
    }
}
