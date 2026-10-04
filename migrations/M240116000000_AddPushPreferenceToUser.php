<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * A per-account switch for browser push.
 *
 * `push_subscription` already answers "which browsers has this person left
 * watching?" and `PushSubscriptionRepository::activeForUser()` already fans a
 * message out to all of them at once. What it could not answer was "does this
 * person want push at all right now?", which is a different question and the
 * one a settings page has to be able to ask.
 *
 * The column is deliberately on `{{%user}}` and not in `site_setting`:
 * `site_setting` is the single admin-owned row set for the whole site, so a
 * switch there would silence every account in the country at once. Per-person
 * consent has to live on the person.
 *
 * DEFAULT TRUE is the important half. Every row that already exists — and every
 * row a signup creates before this migration's default would be applied — must
 * keep receiving the notifications they already receive. A migration that
 * silences live accounts is not a feature.
 *
 * Turning the switch off is a server-side stop, not a browser-side one: the
 * rows stay in `push_subscription` (deactivated by the action), because the
 * user is allowed to turn it back on later and should not have to re-grant a
 * permission prompt on every other browser they own just to do so.
 */
final class M240116000000_AddPushPreferenceToUser implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%user}}', 'push_enabled', 'boolean NOT NULL DEFAULT TRUE');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn('{{%user}}', 'push_enabled');
    }
}
