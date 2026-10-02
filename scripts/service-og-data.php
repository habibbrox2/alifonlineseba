<?php

declare(strict_types=1);

/**
 * Dump the per-service data the OG card generator needs, as JSON on stdout.
 *
 * `scripts/generate-service-og-image.py` shells out to this rather than
 * re-implementing the query, because the accent resolution is not something to
 * duplicate in Python: CategoryAccent is the single source of truth for how a
 * category maps to a palette key (explicit `accent` column, then keywords, then
 * a hash of the slug). A Python copy would silently drift and start painting
 * cards in colours the UI does not use.
 *
 * `history-probe-%` rows are excluded: they are disposable service-request
 * fixtures left behind by test runs, not real services, and a card for each
 * would only bloat the output directory.
 *
 * Usage: php scripts/service-og-data.php > runtime/og/services.json
 */

use App\Service\CategoryAccent;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$dsn = $_ENV['DB_DSN'] ?? sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $_ENV['DB_HOST'] ?? '127.0.0.1',
    $_ENV['DB_PORT'] ?? '3306',
    $_ENV['DB_NAME'] ?? '',
    $_ENV['DB_CHARSET'] ?? 'utf8mb4',
);

$pdo = new PDO($dsn, $_ENV['DB_USERNAME'] ?? '', $_ENV['DB_PASSWORD'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$rows = $pdo->query(
    'SELECT s.slug, s.name, s.description, s.price, c.name AS category_name, c.slug AS category_slug, c.accent
       FROM service s
       LEFT JOIN service_category c ON c.id = s.category_id
      WHERE s.deleted_at IS NULL AND s.status = "active"
        AND s.slug NOT LIKE "history-probe-%"
      ORDER BY s.id'
)->fetchAll(PDO::FETCH_ASSOC);

$accents = new CategoryAccent();
$out = [];
foreach ($rows as $row) {
    $category = [
        'slug' => $row['category_slug'] ?? '',
        'name' => $row['category_name'] ?? '',
        'accent' => $row['accent'] ?? null,
    ];

    $out[] = [
        'slug' => $row['slug'],
        'name' => $row['name'],
        'description' => $row['description'] ?? '',
        'price' => (float) $row['price'],
        'category' => $row['category_name'] ?? '',
        'category_slug' => $row['category_slug'] ?? '',
        'accent' => $accents->key($category),
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
