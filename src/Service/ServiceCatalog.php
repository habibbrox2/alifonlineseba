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
 * other half is that the endpoint returns the seven columns a card uses rather
 * than `SELECT *`, so the payload is ~250 KB of catalogue instead of the whole
 * table.
 */
final readonly class ServiceCatalog
{
    public function __construct(
        private ServiceRepository $services,
        private CategoryAccent $accents,
    ) {}

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
     * One row per active service: the card's fields, plus the two values the
     * grid cannot work out on its own — the category slug the chips filter by
     * and the accent that tints the card.
     *
     * @return list<array{slug: string, name: string, description: string|null,
     *                   price: mixed, badge: string|null,
     *                   category_slug: string|null, accent: string}>
     */
    public function services(): array
    {
        $categories = [];
        foreach ($this->services->allCategories() as $cat) {
            $categories[(int) $cat['id']] = [
                'slug' => (string) $cat['slug'],
                'accent' => $this->accents->key($cat),
            ];
        }

        return array_map(static function (array $service) use ($categories): array {
            $category = $categories[(int) $service['category_id']] ?? null;

            return [
                'slug' => (string) $service['slug'],
                'name' => (string) $service['name'],
                'description' => $service['description'],
                'price' => $service['price'],
                'badge' => $service['badge'] ?? null,
                'category_slug' => $category['slug'] ?? null,
                'accent' => $category['accent'] ?? CategoryAccent::DEFAULT_ACCENT,
            ];
        }, $this->services->gridServices());
    }
}
