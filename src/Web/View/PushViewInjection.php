<?php

declare(strict_types=1);

namespace App\Web\View;

use App\Notification\Push\VapidKeys;
use Yiisoft\Yii\View\Renderer\CommonParametersInjectionInterface;

/**
 * Exposes the VAPID *public* key as `vapidPublicKey` in every Twig template.
 *
 * ## Why this is a view injection and not another fetch
 *
 * The site-wide prompt has to work from any page, and it needs a key the
 * moment it decides to ask. `/api/push/key` exists and is the right answer for
 * a page that mounts the prompt long after load, but here it would put a
 * round trip — and one more thing that can fail — in front of the one moment
 * the browser is willing to prompt. The key is public by definition; it is
 * already rendered into the HTML on `/app` for exactly this reason.
 *
 * ## Why the result is memoised
 *
 * `getCommonParameters()` runs once per render, and every render is a request
 * on a server where `openssl_pkey_get_details()` is not free. The pair cannot
 * change inside one process, so it is computed at most once per request.
 */
final class PushViewInjection implements CommonParametersInjectionInterface
{
    private ?string $publicKey = null;
    private bool $resolved = false;

    public function getCommonParameters(): array
    {
        if (!$this->resolved) {
            $this->publicKey = VapidKeys::fromEnv()?->publicKey() ?? '';
            $this->resolved = true;
        }

        return [
            'vapidPublicKey' => $this->publicKey,
            // A deployment with no keys must not render a prompt it can never
            // satisfy, so the template tests this rather than the key itself:
            // an empty string is falsy in Twig but reads as a key that is
            // merely late, which is exactly the bug this flag removes.
            'vapidEnabled' => $this->publicKey !== '',
        ];
    }
}