<?php

declare(strict_types=1);

namespace App\Twig;

use App\Env;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig helpers: money, dates, icons, status badges, app env.
 */
final class TwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('icon', [$this, 'icon'], ['is_safe' => ['html']]),
            new TwigFunction('app_env', static fn (): string => (string) Env::get('APP_ENV')),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', [$this, 'money']),
            new TwigFilter('bndate', [$this, 'bngDate']),
            new TwigFilter('statusbadge', [$this, 'statusBadge'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Inline Lucide icon via <use> against the bundled sprite.
     */
    public function icon(string $name, string $class = 'w-5 h-5'): string
    {
        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#' . htmlspecialchars($name, ENT_QUOTES) . '"></use></svg>';
    }

    public function money(float|int|string|null $amount): string
    {
        return '৳ ' . number_format((float) ($amount ?? 0), 2);
    }

    public function bngDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return '—';
        }
        $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1] . ' ' . date('Y, g:i a', $ts);
    }
}
