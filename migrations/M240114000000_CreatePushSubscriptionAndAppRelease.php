<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Browser Web Push subscriptions and self-hosted Android releases.
 *
 * Two independent features land together because both are served from the same
 * new `/app` page, and because neither is useful to the other:
 *
 * - `{{%push_subscription}}` stores the per-browser push endpoint handed over by
 *   the Push API. A logged-out visitor has no `user_id`, so the column is
 *   nullable: the endpoint is a bearer-ish secret and we keep it either way.
 *   The UNIQUE index on `endpoint` is what makes a re-subscribe (which returns
 *   a brand new endpoint every time the user grants permission again) an
 *   upsert rather than an unbounded pile of dead rows.
 *
 * - `{{%app_release}}` holds the APK metadata. The bytes themselves live on
 *   disk outside the web root (see `AppReleaseService`); this table is only
 *   the manifest the app and the download banner read. Only one row is
 *   `is_published` at a time, which the partial-state is enforced in the
 *   service rather than by a constraint, because "unpublish the old one and
 *   publish the new one" has to happen in one transaction.
 */
final class M240114000000_CreatePushSubscriptionAndAppRelease implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->createTable('{{%push_subscription}}', [
            'id' => 'pk',
            // NULL for an anonymous visitor; set the moment they log in so a
            // later notification can reach the same browser.
            'user_id' => 'integer NULL',
            // The push service endpoint. 512 is generous (Mozilla endpoints are
            // ~190 chars) and the UNIQUE index is what stops duplicate rows.
            'endpoint' => 'string(512) NOT NULL',
            // The browser's P-256 public key and 16-byte auth secret, base64url.
            'p256dh' => 'string(128) NOT NULL',
            'auth' => 'string(64) NOT NULL',
            'user_agent' => 'string(255) NULL',
            'is_active' => 'boolean NOT NULL DEFAULT TRUE',
            // Bumped on every successful send; lets the purge job retire
            // subscriptions whose browser is long gone.
            'last_seen_at' => 'datetime NULL',
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ]);
        $b->createIndex('{{%push_subscription}}', 'ux_push_subscription_endpoint', 'endpoint', 'UNIQUE');
        $b->createIndex('{{%push_subscription}}', 'ix_push_subscription_user', 'user_id');
        $b->createIndex('{{%push_subscription}}', 'ix_push_subscription_active', 'is_active');
        $b->addForeignKey('{{%push_subscription}}', 'fk_push_subscription_user', 'user_id', '{{%user}}', 'id');

        $b->createTable('{{%app_release}}', [
            'id' => 'pk',
            // Monotonic; the Android app compares this against its own
            // BuildConfig.VERSION_CODE to decide whether to offer an update.
            'version_code' => 'integer NOT NULL',
            'version_name' => 'string(32) NOT NULL',
            // Path relative to the releases directory, never an absolute path
            // and never a URL — resolution is the service's job.
            'apk_path' => 'string(255) NOT NULL',
            'apk_size' => 'integer NOT NULL DEFAULT 0',
            // Published so the download page and the app can verify the file
            // they were handed is the one that was signed off.
            'sha256' => 'string(64) NOT NULL',
            // Block installs below this. A release that is merely "latest" can
            // still declare a floor for clients too old to keep working.
            'min_version_code' => 'integer NOT NULL DEFAULT 0',
            'release_notes' => 'text NULL',
            'is_published' => 'boolean NOT NULL DEFAULT FALSE',
            'published_at' => 'datetime NULL',
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ]);
        $b->createIndex('{{%app_release}}', 'ux_app_release_version_code', 'version_code', 'UNIQUE');
        $b->createIndex('{{%app_release}}', 'ix_app_release_published', 'is_published');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable('{{%app_release}}');
        $b->dropTable('{{%push_subscription}}');
    }
}
