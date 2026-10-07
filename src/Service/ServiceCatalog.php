<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ServiceRepository;

/**
 * The service catalogue in the shape a storefront grid draws it.
 *
 * Two callers need the same answer and must not drift apart: the dashboard
 * page asks for the category chips, and `/api/catalog` asks for the cards'
 * rows. Both previously lived in `DashboardAction`, which meant the enrichment
 * (category slug for filtering, accent for tinting) existed only where the
 * data was inlined into the HTML — and inlining 3 135 services is what made
 * that page 2.7 MB. Moving the rows behind a fetch is only half the fix; the
 * other half is that the endpoint returns the eight columns a card uses rather
 * than `SELECT *`, so the payload is ~250 KB of catalogue instead of the whole
 * table.
 */
final readonly class ServiceCatalog
{
    private IconLibrary $icons;

    public function __construct(
        private ServiceRepository $services,
        private CategoryAccent $accents,
    ) {
        // The card draws its icon through <use> against the sprite, where a
        // name the sprite does not know renders as blank space — the exact
        // fate TwigExtension::icon() avoids by checking it too. A second
        // reader of the file per request is the price of not shipping that
        // knowledge to the client.
        $this->icons = new IconLibrary();
    }

    /**
     * Active categories, each tagged with its palette key for the chip bar.
     *
     * @return list<array<string, mixed>>
     */
    public function categories(): array
    {
        return array_map(
            fn (array $cat): array => $cat + ['accent' => $this->accents->key($cat)],
            $this->services->allCategories(),
        );
    }

    /**
     * One row per active service: the card's fields, plus the values the
     * grid cannot work out on its own — the category slug the chips filter by,
     * the accent that tints the card, and the icon that leads it (the
     * service's own, else its category's, else the generic grid glyph).
     *
     * @return list<array{slug: string, name: string, description: string|null,
     *                   price: mixed, badge: string|null,
     *                   category_slug: string|null, accent: string,
     *                   icon: string}>
     */
    public function services(): array
    {
        $categories = [];
        foreach ($this->services->allCategories() as $cat) {
            $categories[(int) $cat['id']] = [
                'slug' => (string) $cat['slug'],
                'accent' => $this->accents->key($cat),
                'icon' => (string) ($cat['icon'] ?? ''),
            ];
        }

        return array_map(function (array $service) use ($categories): array {
            $category = $categories[(int) $service['category_id']] ?? null;

            return [
                'slug' => (string) $service['slug'],
                'name' => (string) $service['name'],
                'description' => $service['description'],
                'price' => $service['price'],
                'badge' => $service['badge'] ?? null,
                'category_slug' => $category['slug'] ?? null,
                'accent' => $category['accent'] ?? CategoryAccent::DEFAULT_ACCENT,
                'icon' => $this->drawableIcon($service['icon'] ?? null)
                    ?? $this->drawableIcon($category['icon'] ?? null)
                    ?? 'grid',
            ];
        }, $this->services->gridServices());
    }

    /**
     * A sprite name this build can actually draw, or null when there is none.
     *
     * Null (rather than the raw name) is what hands the caller the next link
     * in the fallback chain: an admin may have typed a name a lucide upgrade
     * renamed, and a `<use>` pointing at a missing symbol draws nothing —
     * blank space reads as a broken page, a substituted icon reads as a
     * design choice. An unbuilt checkout has no sprite to check against, so
     * the name is passed through untouched: returning null for everything
     * would silently downgrade every deployment that has not run the build.
     */
    private function drawableIcon(mixed $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $drawable = $this->icons->spriteNames();
        if ($drawable === []) {
            return $name;
        }

        return in_array($name, $drawable, true) ? $name : null;
    }
}
