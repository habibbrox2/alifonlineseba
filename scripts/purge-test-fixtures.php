<?php

declare(strict_types=1);

/**
 * Delete test-fixture rows the suite left behind in the development database.
 *
 * Every functional test creates users with an `@example.test` email and
 * removes them in `_after()`. A run that dies on a failed assertion — or is
 * interrupted — never reaches `_after()`, and the rows survive. They are not
 * harmless: `NotificationManager::adminIds()` fans out to every active
 * admin, so one abandoned fixture admin makes every admin-fan-out test count
 * 100+ recipients instead of one.
 *
 * Scoped deliberately narrow. The predicate is the email domain, which only
 * the test suite writes — the seed command uses `@demo.local` — so a real
 * account can never match. `--dry-run` reports without deleting.
 *
 * Dependents go first, in FK order, and the whole thing is one transaction:
 * a half-finished purge would leave the database in a worse state than the
 * residue it was clearing.
 *
 * Usage: php scripts/purge-test-fixtures.php [--dry-run]
 */

$dryRun = in_array('--dry-run', $argv, true);

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', '127.0.0.1', 'th_tools');
$pdo = new PDO($dsn, 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

/** Every fixture user id, captured once so the deletes cannot drift mid-run. */
$fixtureIds = $pdo
    ->query("SELECT `id` FROM `user` WHERE `email` LIKE '%@example.test'")
    ->fetchAll(PDO::FETCH_COLUMN);

if ($fixtureIds === []) {
    echo "No @example.test fixture rows found. Nothing to do.\n";
    exit(0);
}

$ids = implode(',', array_map('intval', $fixtureIds));
$in = "IN ($ids)";

/**
 * Child tables and the column pointing at the fixture users.
 *
 * Ordered so that a row is removed before whatever references it. This is the
 * list `information_schema` reports as referencing `user`, plus the self
 * reference, and it is deliberately explicit rather than discovered at
 * runtime: a table added to the schema with a user FK and not added here
 * should fail the purge loudly, not be silently skipped.
 */
$targets = [
    ['table' => 'notification_queue', 'col' => 'user_id'],
    ['table' => 'notification', 'col' => 'user_id'],
    ['table' => 'notification_device', 'col' => 'user_id'],
    ['table' => 'notification_preference', 'col' => 'user_id'],
    ['table' => 'push_subscription', 'col' => 'user_id'],
    ['table' => 'api_token', 'col' => 'user_id'],
    ['table' => 'bot_connection', 'col' => 'user_id'],
    ['table' => 'activity_log', 'col' => 'user_id'],
    ['table' => 'transaction', 'col' => 'user_id'],
    ['table' => 'transaction', 'col' => 'admin_id'],
    ['table' => 'service_order', 'col' => 'user_id'],
    ['table' => 'service_order', 'col' => 'claimed_by'],
    ['table' => 'service_order', 'col' => 'approved_by'],
    ['table' => 'service_order', 'col' => 'deliverable_uploaded_by'],
    ['table' => 'admin_withdraw_request', 'col' => 'admin_id'],
    ['table' => 'admin_withdraw_request', 'col' => 'claimed_by'],
    ['table' => 'admin_withdraw_request', 'col' => 'reviewed_by'],
    ['table' => 'topup_request', 'col' => 'user_id'],
    ['table' => 'topup_request', 'col' => 'claimed_by'],
    ['table' => 'topup_request', 'col' => 'reviewed_by'],
    ['table' => 'referral', 'col' => 'referrer_id'],
    ['table' => 'referral', 'col' => 'referee_id'],
    ['table' => 'referral', 'col' => 'reviewed_by'],
    ['table' => 'site_setting', 'col' => 'updated_by'],
    ['table' => 'user', 'col' => 'referred_by'],
];

// notification_delivery has no user FK — it hangs off the queue, so it is
// cleared by joining to the fixture users' queue rows rather than by its own
// column. Handled separately because it does not fit the (table, col) shape.
$deliverySql = 'DELETE nd FROM `notification_delivery` nd'
    . ' JOIN `notification_queue` q ON q.`id` = nd.`queue_id`'
    . " WHERE q.`user_id` $in";

echo $dryRun ? "Dry run — nothing will be deleted.\n\n" : "Purging test fixtures.\n\n";

$pdo->beginTransaction();
try {
    $count = $pdo->query($deliverySql)->rowCount();
    printf("  %-46s %d\n", 'notification_delivery (via queue)', $count);

    foreach ($targets as $target) {
        $sql = sprintf(
            'DELETE FROM `%s` WHERE `%s` %s',
            $target['table'],
            $target['col'],
            $in,
        );
        printf("  %-46s %d\n", $target['table'] . '.' . $target['col'], $pdo->query($sql)->rowCount());
    }

    // The users themselves, still narrowed by the domain predicate so a row
    // that gained an id outside the captured set cannot slip through.
    $userSql = "DELETE FROM `user` WHERE `id` $in AND `email` LIKE '%@example.test'";
    $deleted = $pdo->query($userSql)->rowCount();
    printf("  %-46s %d\n", 'user (the fixture accounts)', $deleted);

    if ($dryRun) {
        $pdo->rollBack();
        echo "\nDry run complete — rolled back.\n";
    } else {
        $pdo->commit();
        printf("\nDeleted %d fixture users.\n", $deleted);
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\nPurge failed and was rolled back: " . $e->getMessage() . "\n");
    exit(1);
}