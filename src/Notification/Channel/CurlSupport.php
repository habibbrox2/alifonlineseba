<?php

declare(strict_types=1);

namespace App\Notification\Channel;

/**
 * Whether this host can send HTTP at all — the one thing the three push
 * channels have in common and the one thing no credentials check covers.
 *
 * ## Why this exists
 *
 * FCM, Telegram and Web Push are the only curl users in the application, and
 * all three run from cron. On a shared host that ships PHP without ext-curl,
 * or with `disable_functions=curl_exec` in php.ini, the first `curl_init()`
 * was a fatal Error: the worker died on its first job, the batch it had just
 * claimed never settled, and the next tick claimed the same rows and died the
 * same way. Nothing was ever logged, and `/admin/notifications` showed the
 * queue depth climbing forever — the exact symptom that is undiagnosable from
 * the outside, because a missing extension is invisible in every credentials
 * check the app has.
 *
 * Answering the question once, here, turns that into an ordinary dead-letter
 * carrying a message an operator can act on. The application itself works
 * without curl — in-app notifications, orders, payouts, the whole web app —
 * so this is a channel-level answer, not an install-time requirement: the
 * composer manifest deliberately does not demand ext-curl.
 */
final class CurlSupport
{
    /**
     * '' when the channels may send, otherwise the reason they cannot.
     *
     * `curl_exec` is checked alongside `curl_init` because hosts disable them
     * selectively: a php.ini with `disable_functions=curl_exec` still answers
     * `function_exists('curl_init') === true` and then fatals on the first
     * call that matters. PHP 8 removes a disabled function from the symbol
     * table, so `function_exists()` is the reliable probe.
     */
    public static function blocker(): string
    {
        foreach (['curl_init', 'curl_exec'] as $function) {
            if (!\function_exists($function)) {
                return "The PHP curl extension is not usable on this host ({$function} is disabled or missing) — push notifications cannot be sent.";
            }
        }

        return '';
    }
}
