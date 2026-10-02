<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SettingsRepository;
use App\Repository\TopupRepository;

/**
 * Official brand identity of the mobile financial services we accept.
 *
 * A recharge page that paints every method in the site green reads as an
 * anonymous form, not as "send money to bKash". Users recognise bKash's pink and
 * Rocket's purple faster than they read the label, and picking the wrong
 * wallet is an expensive support ticket: the payment lands in a number the
 * operator never checks. So each method carries its real brand colour and mark,
 * and the step-1 picker, the step-2 pay page and the request history all draw
 * from this one table.
 *
 * Design notes:
 *
 *  - The display name stays in {@see SettingsRepository::METHOD_LABELS}. That
 *    constant was already the single source of truth for the label and is what
 *    `RechargeAction` hands the template as `methods`; duplicating "bKash" here
 *    would give the two spellings a way to drift apart. `all()` merges it in.
 *  - The colours are the base token, and the only colour stored. Soft/line/ink
 *    and on are deliberately *not* here: they are the brand hue at fixed tints,
 *    so they are derived rather than hand-written — twelve more hex values per
 *    table is how the light and dark tables end up disagreeing. See
 *    {@see PaymentBrandPalette} for the derivation and the generated
 *    `.pay-*` block in resources/css/app.css for the resolved values.
 *  - The logo is a path under `public/assets/brand/payment/`. Whether it is
 *    actually served is decided in the Twig layer by `is_file()`, because only
 *    there do we know the asset root; a missing file must degrade to the
 *    initials tile, never to a broken image icon.
 *
 * The method keys here must match {@see TopupRepository::METHODS} — the list the
 * submit path validates against. PaymentBrandTest asserts it.
 */
final class PaymentBrand
{
    /**
     * Base brand colour and mark per method key.
     *
     * The colours are the operators' published brand colours:
     *   bKash  #E2136E  the brand's magenta-pink
     *   Nagad  #EE1C25  the red of the official mark, paired with #F6921E orange
     *   Rocket #8B3392  the purple sampled from the official Airtel Rocket mark
     *
     * The Nagad and Rocket marks use a second hue inside the artwork itself; the
     * table only needs the one each UI element is tinted from.
     *
     * @var array<string, array{color: string, logo: string}>
     */
    public const BRANDS = [
        'bkash' => [
            'color' => '#E2136E',
            'logo' => '/assets/brand/payment/bkash.svg',
        ],
        'nagad' => [
            'color' => '#EE1C25',
            'logo' => '/assets/brand/payment/nagad.svg',
        ],
        'rocket' => [
            'color' => '#8B3392',
            'logo' => '/assets/brand/payment/rocket.svg',
        ],
    ];

    /**
     * The colour a method with no brand entry renders in.
     *
     * Held here rather than inlined at each use because there are two of them
     * that must agree: the view model handed to the template, and the
     * `.pay-default` row in the stylesheet, which is generated from this
     * constant by {@see PaymentBrandPalette}. Two literals is how a fallback
     * ends up green in one place and teal in the other.
     */
    public const FALLBACK_COLOR = '#0d8f68';

    /** @return list<string> the brand keys, in the order the picker shows them */
    public function keys(): array
    {
        return array_keys(self::BRANDS);
    }

    /**
     * Every method, label included, as a `key => view model` map.
     *
     * @return array<string, array{key: string, label: string, css_class: string, color: string, logo: string, initials: string}>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->keys() as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    /**
     * One method's view model.
     *
     * The `initials` fallback is not decoration: a missing logo file, a
     * half-finished deploy or a wiped public dir must still produce a usable
     * picker, because the radio input underneath it is what the form actually
     * submits. It mirrors the old `{{ label|slice(0, 2)|upper }}` tile.
     *
     * @return array{key: string, label: string, css_class: string, color: string, logo: string, initials: string}
     */
    public function get(string $key): array
    {
        $key = strtolower(trim($key));
        $brand = self::BRANDS[$key] ?? null;

        // An unknown key still gets a complete, unbranded view model rather than
        // an exception: `recharge-pay.twig` builds the key from a query
        // parameter, and a missing method there must render "not configured",
        // not a 500.
        $label = SettingsRepository::METHOD_LABELS[$key] ?? ($key === '' ? 'Unknown' : ucfirst($key));

        return [
            'key' => $key,
            'label' => $label,
            'css_class' => $this->cssClass($key),
            'color' => $brand['color'] ?? self::FALLBACK_COLOR,
            'logo' => $brand['logo'] ?? '',
            'initials' => strtoupper(substr($label, 0, 2)),
        ];
    }

    /**
     * The CSS class that activates a method's brand tokens, e.g. `pay-bkash`.
     *
     * Assembled at runtime like the category accents, which is exactly why the
     * matching rules live outside `@layer` — see the palette comment in
     * resources/css/app.css.
     */
    public function cssClass(string $key): string
    {
        $key = strtolower(trim($key));

        return 'pay-' . (isset(self::BRANDS[$key]) ? $key : 'default');
    }

    /**
     * @return list<string> the method keys, if the two tables have drifted
     */
    public function missingFromRepository(): array
    {
        return array_values(array_diff(TopupRepository::METHODS, $this->keys()));
    }
}
