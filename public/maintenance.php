<?php

declare(strict_types=1);

/**
 * Maintenance page — served while `runtime/maintenance.lock` exists.
 *
 * This file is an entry point, not a library: `public/index.php` requires it
 * and lets it finish the request.
 *
 * It is deliberately self-contained — no autoloader, no .env, no framework, no
 * stylesheet, no font, nothing from `public/assets/`. That is the whole point:
 * the page has to keep working while `vendor/` is half-written by rsync and the
 * compiled CSS is missing, because the moment it cannot be rendered is exactly
 * the moment a visitor is most likely to be looking at it. Nothing here can
 * fatal, because nothing here depends on code that a deploy can break.
 *
 * Keep it that way: no `require`, no `App\` classes, no `Env::get()`.
 */

// How long a browser should wait before trying again. The deploy workflow
// clears the lock within seconds; this only matters if the deploy failed.
const MAINTENANCE_RETRY_AFTER = 60;

$lockFile = dirname(__DIR__) . '/runtime/maintenance.lock';
$reason = '';

if (is_file($lockFile) && is_readable($lockFile)) {
    // First line only, and bounded: the file is written by a shell `printf` on
    // a deploy, but a stray multi-kilobyte write should not become the page.
    $firstLine = strtok((string) @file_get_contents($lockFile, false), "\r\n");
    $reason = trim($firstLine === false ? '' : $firstLine);
}

// Self-referencing link for the "retry now" button. Escaped because it comes
// straight from the request line, and escaped again below for the href.
$selfUri = htmlspecialchars(
    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8',
);

$reasonHtml = $reason === ''
    ? ''
    : '<p class="reason">' . htmlspecialchars(
        // mb_strimwidth is not guaranteed here: this file runs before the
        // autoloader, and a host missing ext-mbstring must still get a page
        // rather than a fatal on the one code path that cannot afford one.
        function_exists('mb_strimwidth')
            ? mb_strimwidth($reason, 0, 160, '…')
            : substr($reason, 0, 160),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8',
    ) . '</p>';

if (!headers_sent()) {
    // 503 + Retry-After is what makes caches, the TWA and monitoring back off
    // instead of hammering a host that is mid-deploy. no-store keeps a CDN or
    // Cloudflare from pinning the maintenance page for the next visitor after
    // the lock is gone.
    header('HTTP/1.1 503 Service Unavailable', true, 503);
    header('Retry-After: ' . MAINTENANCE_RETRY_AFTER);
    header('Cache-Control: no-store, max-age=0');
    header('Content-Type: text/html; charset=utf-8');
}

// Auto-retry, so the visitor does not have to press reload. The visible button
// is the fallback for browsers that honour no meta refresh, and for anyone who
// would rather see it land.
echo <<<HTML
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="refresh" content="15">
<title>আপডেট হচ্ছে — Alif Tools</title>
<style>
  /* Inline, because public/assets/css/app.css may be the file rsync is
     rewriting right now. Colours mirror tailwind.config.js: primary/surface. */
  :root {
    color-scheme: light dark;
    --bg: #f4f7f6;
    --card: #ffffff;
    --text: #083f31;
    --muted: #5b7a70;
    --line: #e8eeec;
    --brand-a: #0d8f68;
    --brand-b: #0b6e51;
    --glow: rgb(13 143 104 / 0.16);
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #071310;
      --card: #0c1f1a;
      --text: #d1fae5;
      --muted: #8aa8a0;
      --line: #17342c;
    }
  }
  * { box-sizing: border-box; }
  html, body { height: 100%; }
  body {
    margin: 0;
    display: grid;
    place-items: center;
    padding: 24px;
    background: var(--bg);
    color: var(--text);
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans Bengali",
                 "Hind Siliguri", sans-serif;
    -webkit-font-smoothing: antialiased;
  }
  .card {
    width: 100%;
    max-width: 30rem;
    padding: 2.5rem 1.75rem 2rem;
    text-align: center;
    background: var(--card);
    border: 1px solid var(--line);
    border-radius: 1.125rem;
    box-shadow: 0 12px 32px -6px rgb(8 63 49 / 0.16);
  }
  .mark { width: 3.5rem; height: 3.5rem; margin: 0 auto 1.25rem; display: block; }
  h1 { margin: 0 0 .5rem; font-size: 1.375rem; line-height: 1.4; font-weight: 700; }
  p { margin: 0; color: var(--muted); font-size: .9375rem; line-height: 1.7; }
  .en { display: block; margin-top: .25rem; font-size: .8125rem; opacity: .8; }
  .bar {
    position: relative;
    height: .25rem;
    margin: 1.75rem 0 1.25rem;
    overflow: hidden;
    background: var(--line);
    border-radius: 999px;
  }
  .bar::after {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 40%;
    background: linear-gradient(90deg, var(--brand-a), var(--brand-b));
    border-radius: 999px;
    animation: slide 1.4s ease-in-out infinite;
  }
  @keyframes slide {
    0%   { transform: translateX(-100%); }
    100% { transform: translateX(250%); }
  }
  .reason {
    display: inline-block;
    margin-bottom: 1.5rem;
    padding: .375rem .75rem;
    background: color-mix(in srgb, var(--brand-a) 10%, transparent);
    border-radius: 999px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: .75rem;
    color: var(--brand-a);
    word-break: break-all;
  }
  a {
    display: inline-block;
    padding: .625rem 1.25rem;
    background: linear-gradient(135deg, var(--brand-a), var(--brand-b));
    color: #fff;
    font-size: .875rem;
    font-weight: 600;
    text-decoration: none;
    border-radius: .625rem;
    box-shadow: 0 6px 20px -3px var(--glow);
  }
  a:hover { filter: brightness(1.07); }
  footer { margin-top: 1.75rem; font-size: .75rem; color: var(--muted); }
  @media (prefers-reduced-motion: reduce) {
    .bar::after { animation: none; width: 100%; opacity: .5; }
  }
</style>
</head>
<body>
<main class="card">
  <svg class="mark" viewBox="0 0 64 64" aria-hidden="true">
    <defs>
      <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0" stop-color="#0d8f68"/>
        <stop offset="1" stop-color="#083f31"/>
      </linearGradient>
    </defs>
    <rect width="64" height="64" rx="16" fill="url(#g)"/>
    <path d="M34.5 14 20 36h10l-2.5 14L42 27H32l2.5-13z" fill="#f5b301"/>
  </svg>

  <h1>সাইটটি আপডেট হচ্ছে</h1>
  <p>
    আমরা নতুন সংস্করণ ইনস্টল করছি। কয়েক সেকেন্ডের মধ্যেই সব ঠিক হয়ে যাবে।
    <span class="en">We are deploying a new version — back in a moment.</span>
  </p>

  <div class="bar" role="progressbar" aria-label="আপডেট চলছে"></div>

  {$reasonHtml}

  <a href="{$selfUri}">এখনই আবার চেষ্টা করুন</a>

  <footer>allseba.online</footer>
</main>
</body>
</html>
HTML;

exit(0);