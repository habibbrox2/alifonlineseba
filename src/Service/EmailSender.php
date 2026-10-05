<?php

declare(strict_types=1);

namespace App\Service;

use App\Env;

/**
 * Outgoing mail for the password-reset link, and nothing else.
 *
 * There is deliberately no mail library in this project: composer.json has no
 * transport, shared hosting gives us `sendmail` behind `mail()`, and one
 * password-reset link a week does not justify an SMTP client to maintain. If a
 * deployment outgrows this, the whole interface is `send()` — swap the body of
 * this class for symfony/mailer and nothing that calls it changes.
 *
 * Failures are *reported*, never thrown: the reset flow's contract is that a
 * user who asks for a link is told what actually happened, and a host with no
 * MTA (`mail()` returns false silently on XAMPP before sendmail is configured)
 * must not turn into a 500 on a page whose whole job is to hand out one URL.
 */
final class EmailSender
{
    /** '' when mail can be sent, otherwise the reason it cannot. */
    public function blocker(): string
    {
        if (!\function_exists('mail')) {
            return 'The PHP mail() function is not available on this host.';
        }
        if ((string) Env::get('MAIL_FROM', '') === '') {
            return 'MAIL_FROM is not set.';
        }
        return '';
    }

    /**
     * Send a plain-text message. Returns false (never throws) when the host
     * has no working MTA or the recipient is not a valid address.
     */
    public function send(string $to, string $subject, string $body): bool
    {
        if ($this->blocker() !== '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $from = (string) Env::get('MAIL_FROM', '');
        $name = (string) Env::get('MAIL_FROM_NAME', 'All Seba');

        // RFC 2047: a raw UTF-8 subject is dropped by several MTAs, and this
        // site's subjects are Bengali. base64 encoded-word is the form every
        // MTA accepts.
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers = [
            'From: ' . sprintf('%s <%s>', $name, $from),
            'Reply-To: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: AllSeba',
        ];

        // @: an MTA that rejects the message is a delivery failure the caller
        // reports to the user, not a PHP warning on a public page.
        return @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
    }
}
