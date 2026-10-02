<?php

declare(strict_types=1);

namespace App\Twig;

use App\Env;
use App\Service\CategoryAccent;
use App\Service\IconLibrary;
use App\Service\PaymentBrand;
use App\Service\StatusPresenter;
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
            new TwigFunction('asset', [$this, 'asset']),
            new TwigFunction('accent', [$this, 'accent']),
            new TwigFunction('accent_key', [$this, 'accentKey']),
            new TwigFunction('accent_options', [$this, 'accentOptions']),
            new TwigFunction('variant_accent', [$this, 'variantAccent']),
            new TwigFunction('service_og_image', [$this, 'serviceOgImage']),
            new TwigFunction('payment_brand', [$this, 'paymentBrand']),
            new TwigFunction('icon_index_url', [$this, 'iconIndexUrl']),
            new TwigFunction('icon_available', [$this, 'iconAvailable']),
        ];
    }

    /**
     * Brand identity of one payment method: label, colour, logo.
     *
     * The `logo` key is resolved here rather than in {@see PaymentBrand}
     * because this is the layer that knows the asset root, and because a brand
     * colour is worth nothing without the mark beside it. The existence check
     * mirrors serviceOgImage(): the marks are source files under
     * `public/assets/brand/payment/`, and a deploy that shipped the code
     * without them must show the initials tile rather than a broken image in
     * the middle of a payment form. So `logo` comes back null when the file is
     * missing and the template decides what to draw instead.
     *
     * @return array{key: string, label: string, css_class: string, color: string,
     *               logo: ?string, logo_path: string, initials: string}
     */
    public function paymentBrand(string $key): array
    {
        $brand = $this->brands()->get($key);
        $path = $brand['logo'];

        // array_merge, not `+`: the union operator keeps the left-hand value
        // for a duplicate key, so `logo` would stay the raw path and the
        // existence check below would be silently discarded.
        $brand['logo'] = $path !== '' && is_file(dirname(__DIR__, 2) . '/public' . $path)
            ? $this->asset($path)
            : null;
        $brand['logo_path'] = $path;

        return $brand;
    }

    /**
     * Share-card geometry for a service page.
     *
     * Returns the per-service 1200x600 banner painted in the category's accent
     * colour, or the 1200x630 site-wide cover when that banner has not been
     * generated. The fallback matters because the banners are build artefacts:
     * adding a service and shipping the code before running
     * `scripts/generate-service-og-image.py` would otherwise put a 404 in
     * og:image, and Telegram renders that as a blank grey box in the share
     * sheet rather than falling back to anything.
     *
     * Width/height come back with the path rather than being restated in the
     * template, because getting them wrong is silent: the crawler lays the card
     * out from these numbers before the image loads, so a 1200x600 file
     * declared as 630 tall shifts the whole preview.
     *
     * @param array|object $service Row with a `slug` key/property. Typed loosely
     *        on purpose: ServiceDetailAction hands the template a repository
     *        array while other actions pass hydrated objects, and Twig resolves
     *        `.slug` against either.
     *
     * @return array{path: string, width: int, height: int}
     */
    public function serviceOgImage(array|object $service): array
    {
        $cover = ['path' => '/assets/img/og-cover.png', 'width' => 1200, 'height' => 630];

        $raw = is_array($service) ? ($service['slug'] ?? null) : ($service->slug ?? null);
        $slug = is_scalar($raw) ? (string) $raw : '';
        if ($slug === '' || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            return $cover;
        }

        $path = '/assets/img/og/service-' . $slug . '.png';

        // Deliberately not passed through asset(): the og:image URL is fetched by
        // crawlers and chat clients, not by a browser holding the page, and a
        // `?v=` mtime on every share link leaks the deploy time and defeats edge
        // caching of the image.
        return is_file(dirname(__DIR__, 2) . '/public' . $path)
            ? ['path' => $path, 'width' => 1200, 'height' => 600]
            : $cover;
    }

    /**
     * Public asset URL with a cache-busting version query.
     *
     * CSS/JS/sprite live in the gitignored `public/assets` build directory, so a
     * deploy swaps their contents while the path stays identical. Without a version
     * the query browsers keep serving a stale stylesheet and the new UI never appears.
     *
     * @param string $path Public path such as `/assets/css/app.css`.
     */
    public function asset(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/public' . $path;
        $mtime = is_file($file) ? (int) filemtime($file) : 0;

        return $path . ($mtime > 0 ? '?v=' . $mtime : '');
    }

    /**
     * Lazily built so the extension stays constructible with `new TwigExtension()`
     * (as the unit tests do) — the resolver is stateless and cheap to build.
     */
    private ?CategoryAccent $accents = null;

    private function accents(): CategoryAccent
    {
        return $this->accents ??= new CategoryAccent();
    }

    /**
     * Lazily built, for the same reason as `accents()`: the extension has to
     * stay constructible with `new TwigExtension()` in the unit tests.
     */
    private ?PaymentBrand $brands = null;

    private function brands(): PaymentBrand
    {
        return $this->brands ??= new PaymentBrand();
    }

    /**
     * Lazily built, for the same reason as `brands()`: the unit tests construct
     * this extension with `new TwigExtension()`.
     */
    private ?IconLibrary $icons = null;

    private function icons(): IconLibrary
    {
        return $this->icons ??= new IconLibrary();
    }

    /**
     * Cache-busted URL of the Lucide search index the icon picker fetches.
     *
     * Empty string when the build artefacts are missing, so the picker renders
     * as a plain text box instead of firing a request that 404s.
     */
    public function iconIndexUrl(): string
    {
        return $this->icons()->isBuilt() ? $this->asset($this->icons()->indexUrl()) : '';
    }

    /**
     * Whether the Lucide sprite can draw this name — used to flag a hand-typed
     * value the storefront would render blank.
     */
    public function iconAvailable(string $name): bool
    {
        $name = trim($name);

        return $name !== '' && in_array($name, $this->icons()->spriteNames(), true);
    }

    /**
     * CSS class activating a category's theme accent, e.g. `accent-sky`.
     *
     * @param array<string, mixed>|string|null $category
     */
    public function accent($category): string
    {
        return $this->accents()->cssClass($category);
    }

    /**
     * The raw palette key, e.g. `sky` — handy for Alpine bindings.
     *
     * @param array<string, mixed>|string|null $category
     */
    public function accentKey($category): string
    {
        return $this->accents()->key($category);
    }

    /**
     * Palette options for the admin category form: ['emerald' => 'সবুজ', ...].
     *
     * @return array<string, string>
     */
    public function accentOptions(): array
    {
        return $this->accents()->options();
    }

    /**
     * CSS class for one purchasable variant, e.g. `accent-teal`.
     *
     * `taken` carries the keys already used by earlier siblings so consecutive
     * options in the same picker do not repeat a colour. A template can only
     * hold the classes it has already rendered, so the `accent-` prefix is
     * stripped here rather than forcing the template to keep a second list.
     *
     * @param array<string, mixed>|string|null $variant
     * @param list<string> $taken
     */
    public function variantAccent($variant, $category, int $index, array $taken = []): string
    {
        $keys = array_map(
            static fn (string $class): string => str_starts_with($class, 'accent-') ? substr($class, 7) : $class,
            $taken,
        );

        return $this->accents()->variantCssClass($variant, $this->accents()->key($category), $index, $keys);
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', [$this, 'money']),
            new TwigFilter('bndate', [$this, 'bngDate']),
            new TwigFilter('statusbadge', [$this, 'statusBadge'], ['is_safe' => ['html']]),
            new TwigFilter('statuslabel', [$this, 'statusLabel']),
        ];
    }

    /**
     * Inline Lucide icon via <use> against the bundled sprite.
     *
     * The sprite is a build artefact (`public/assets` is gitignored) and rebuilt
     * from a pinned lucide version, so a name that exists today can vanish after
     * a dependency bump. A missing <use> href renders as silent blank space, which
     * is far worse than a generic icon, so unknown names fall back to "info".
     *
     * @var array<string, true>|null
     */
    private static ?array $knownIcons = null;

    public function icon(string $name, string $class = 'w-5 h-5'): string
    {
        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" aria-hidden="true"><use href="/assets/icons/lucide-sprite.svg#' . htmlspecialchars($this->resolveIcon($name), ENT_QUOTES) . '"></use></svg>';
    }

    private function resolveIcon(string $name): string
    {
        if (self::$knownIcons === null) {
            self::$knownIcons = [];
            $sprite = dirname(__DIR__, 2) . '/public/assets/icons/lucide-sprite.svg';
            if (is_file($sprite)) {
                $svg = (string) file_get_contents($sprite);
                if (preg_match_all('/\sid="([A-Za-z0-9_-]+)"/', $svg, $m) > 0) {
                    self::$knownIcons = array_fill_keys($m[1], true);
                }
            }
        }

        // An unreadable sprite (dev, CLI, tests) must not trigger fallbacks.
        if (self::$knownIcons === []) {
            return $name;
        }

        return isset(self::$knownIcons[$name]) ? $name : 'info';
    }

    public function money(float|int|string|null $amount): string
    {
        return '৳ ' . number_format((float) ($amount ?? 0), 2);
    }

    public function statusBadge(string $status): string
    {
        return StatusPresenter::html($status);
    }

    /**
     * The Bengali label for a status, as plain text.
     *
     * Exposed for the admin status `<select>`, which needs a label per option.
     * Reaching for `StatusPresenter::PENDING` from Twig cannot work: those
     * constants hold the *value* ('pending'), not the wording, so the dropdown
     * would have read "pending" instead of "প্রসেসিং".
     */
    public function statusLabel(string $status): string
    {
        return StatusPresenter::label($status);
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
