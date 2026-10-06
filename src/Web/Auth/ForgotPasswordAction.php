<?php

declare(strict_types=1);

namespace App\Web\Auth;

use App\Auth\Identity;
use App\Service\PasswordResetService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET|POST /forgot-password — choose a channel, start a reset.
 *
 * The response is deliberately identical for "no such account", "this
 * account has no WhatsApp on file" and "the code is on its way": see
 * {@see PasswordResetService}. What this action adds is the channel picker —
 * three radio buttons rather than one hidden default, because a phone-only
 * account cannot recover by email and an account that has never connected
 * the bot cannot recover by Telegram; the person has to be able to say which
 * proof they can actually produce.
 *     * For an OTP channel the flow continues on `/reset-password`, so the row id
     * the code lives in is pinned in the session here and read there. For email
     * the continuation is the link itself, which arrives in the user's mailbox —
     * so this page stays put and shows the "check your inbox" banner instead.
 */
final readonly class ForgotPasswordAction
{
    public function __construct(
        private WebViewRenderer $view,
        private PasswordResetService $resets,
        private UrlGeneratorInterface $url,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getAttribute('identity') instanceof Identity) {
            // Already signed in: the recovery flow exists to get *back* in.
            return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('profile')]);
        }

        $errors = [];
        $old = ['identifier' => '', 'channel' => 'email'];
        $sent = false;

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            $identifier = trim((string) ($input['identifier'] ?? ''));
            $channel = (string) ($input['channel'] ?? 'email');

            if ($identifier === '') {
                $errors['identifier'] = 'ইউজারনেম, মোবাইল নম্বর বা ইমেইল দিন।';
            } elseif (!in_array($channel, PasswordResetService::CHANNELS, true)) {
                $errors['channel'] = 'একটি পদ্ধতি বেছে নিন।';
            } else {
                $result = $this->resets->issue(
                    $identifier,
                    $channel,
                    (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
                    (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                );

                if ($result['channel'] !== 'email') {
                    // Pins *which* row the verify step may spend. Not a secret
                    // (it is useless without the code) — it is what stops a
                    // session from spending a row it was never issued.
                    //
                    // Pinned even when nothing was delivered ($rowId is null
                    // in that case). The redirect below is the same either
                    // way, so bouncing back only when the send failed would
                    // let the flow's second hop tell a stranger which accounts
                    // really have the channel on file — exactly what the
                    // identical response below exists to prevent. And a pin
                    // without a row refuses every code at verify time, so the
                    // visitor lands on the form they asked for and is told the
                    // same "code is invalid" any wrong guess gets.
                    $this->session->set('reset_pending', [
                        'rowId' => $result['rowId'],
                        'channel' => $result['channel'],
                    ]);
                }

                // Same sentence, whatever really happened.
                $this->session->set('flash_success', $this->messageFor($result['channel']));

                // Only the OTP channels continue to a form. The emailed link is
                // the continuation for that channel — and /reset-password with
                // no token and no pinned row is a dead end that bounces here
                // again, so redirecting there would replace "check your inbox"
                // with "no link received" one hop after we sent one. Staying
                // here also leaves the identifier in the form, so "it did not
                // arrive, send again" is one more click on the same page.
                $target = $result['channel'] === 'email' ? 'forgot-password' : 'reset-password';

                return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate($target)]);
            }

            $old['identifier'] = $identifier;
            $old['channel'] = $channel;
        }

        return $this->view->render('site/auth/forgot-password.twig', [
            'errors' => $errors,
            'old' => $old,
            'sent' => $sent,
        ]);
    }

    /** One message for every outcome — including "nothing was sent". */
    private function messageFor(string $channel): string
    {
        return match ($channel) {
            'email' => 'অ্যাকাউন্ট থাকলে পাসওয়ার্ড রিসেট লিংক ইমেইলে পাঠানো হয়েছে। ইনবক্স ও স্প্যাম দেখুন।',
            'whatsapp' => 'অ্যাকাউন্ট থাকলে হোয়াটসঅ্যাপে ভেরিফিকেশন কোড পাঠানো হয়েছে।',
            default => 'অ্যাকাউন্ট থাকলে টেলিগ্রামে ভেরিফিকেশন কোড পাঠানো হয়েছে।',
        };
    }
}
