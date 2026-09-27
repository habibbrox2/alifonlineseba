<?php

declare(strict_types=1);

namespace App\Web;

use Nyholm\Psr7\Response;
use Twig\Environment;

/**
 * Renders friendly error pages for common HTTP status codes.
 */
final class ErrorPages
{
    private const PAGES = [
        400 => ['Bad Request', 'অনুরোধটি সঠিক নয়।'],
        401 => ['Unauthorized', 'প্রবেশ করতে লগইন করুন।'],
        403 => ['Forbidden', 'আপনার এই পেজ দেখার অনুমতি নেই।'],
        404 => ['Page Not Found', 'আপনি যে পেজটি খুঁজছেন সেটি পাওয়া যায়নি।'],
        419 => ['Session Expired', 'সেশন শেষ হয়ে গেছে — আবার চেষ্টা করুন।'],
        429 => ['Too Many Requests', 'অনেক বেশি অনুরোধ — কিছুক্ষণ পর চেষ্টা করুন।'],
        500 => ['Server Error', 'সার্ভারে সমস্যা হয়েছে — পরে চেষ্টা করুন।'],
        503 => ['Service Unavailable', 'সেবা সাময়িকভাবে বন্ধ আছে।'],
    ];

    public function __construct(private readonly Environment $twig) {}

    public function render(int $code): ?Response
    {
        if (!isset(self::PAGES[$code])) {
            return null;
        }

        [$heading, $message] = self::PAGES[$code];

        try {
            $html = $this->twig->render('errors/error.twig', [
                'code' => $code,
                'heading' => $heading,
                'message' => $message,
            ]);
        } catch (\Throwable) {
            return null;
        }

        return new Response($code, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }
}
