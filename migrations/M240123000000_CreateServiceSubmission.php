<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * The answers a user typed into a provider-backed service form, in a table of
 * their own.
 *
 * `service_order.metadata` already carries that data, and it stays there — a
 * retry re-runs the provider from it without the form being filled in twice,
 * and that snapshot has to be exactly what the user submitted. But metadata is
 * a grab bag: `provider`, `input_keys`, `input`, `result`, `attempts`,
 * `admin_note`, `error`. Everything about an order shares one JSON column, so
 * answering a question about form submissions means knowing the internal shape
 * of that blob, and no index can ever reach into it.
 *
 * This table is the same data with a shape that can be asked questions of:
 *
 *   - one row per order, so `service_order_id` is UNIQUE and the submission
 *     cannot drift away from the order that produced it;
 *   - `field_values` is JSON keyed by the *form field name* —
 *     `{"nid_number": "...", "photo": "<hashed storage path>"}` — so a service
 *     that grows a field needs no migration, no new column and no code change.
 *     That is the whole reason it is JSON and not a set of columns: the fields
 *     come from a provider and from an admin's field configuration, and both
 *     change without a deploy.
 *   - `user_id` and `service_id` are denormalised onto the row so "every NID
 *     submission by this customer" and "how many nid-make forms were filled in
 *     this month" are both single indexed reads rather than joins the caller
 *     has to remember to write.
 *
 * `provider` is copied rather than joined so a row stays explainable after the
 * provider that produced it is renamed or removed from the catalog.
 *
 * On the foreign keys: the order cascade is the only one in the schema that
 * deletes on its own, and it is right here — a submission is not a record of
 * anything without the order it belongs to, and an orphan would be unreachable
 * from every page in the product. The service key detaches instead of
 * cascading, because purging a service keeps its orders (that is what
 * `ServiceRepository` already does to `service_order.service_id`) and a
 * customer's answers have to survive the catalogue entry being removed.
 */
final class M240123000000_CreateServiceSubmission implements RevertibleMigrationInterface
{
    /** Same reasoning as M240109: never inherit a latin1 database default. */
    private const CHARSET = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable('{{%service_submission}}', [
            'id' => 'pk',
            // UNIQUE below, which is what makes this 1:1 with the order.
            'service_order_id' => 'integer NOT NULL',
            'user_id' => 'integer NOT NULL',
            // Nullable for the same reason `service_order.service_id` is:
            // purging a service detaches the row rather than destroying it.
            'service_id' => 'integer NULL',
            // The provider key that consumed this form ('mock-nid-make'). Kept
            // as a value rather than a foreign key so the row still says what
            // produced it after the provider is renamed or dropped.
            'provider' => 'string(32) NOT NULL',
            // JSON, keyed by form field name. NOT a column per field: the field
            // list comes from a provider and an admin's configuration, so it
            // changes without a deploy and a column-per-field table would need a
            // migration every time somebody ticked a box in /admin/services.
            'field_values' => 'json NOT NULL',
            'created_at' => 'datetime NOT NULL',
            'updated_at' => 'datetime NOT NULL',
        ], self::CHARSET);

        // The 1:1 guarantee. Also the lookup the PDF action does by order id.
        $b->createIndex(
            '{{%service_submission}}',
            'uk_service_submission_order',
            ['service_order_id'],
            'UNIQUE',
        );
        // "What has this customer submitted", newest first.
        $b->createIndex(
            '{{%service_submission}}',
            'ix_service_submission_user_created',
            ['user_id', 'created_at'],
        );
        // Per-service reporting without touching service_order at all.
        $b->createIndex(
            '{{%service_submission}}',
            'ix_service_submission_service_created',
            ['service_id', 'created_at'],
        );

        $b->addForeignKey(
            '{{%service_submission}}',
            'fk_service_submission_order',
            'service_order_id',
            '{{%service_order}}',
            'id',
            'CASCADE',
            'CASCADE',
        );
        $b->addForeignKey('{{%service_submission}}', 'fk_service_submission_user', 'user_id', '{{%user}}', 'id');
        $b->addForeignKey(
            '{{%service_submission}}',
            'fk_service_submission_service',
            'service_id',
            '{{%service}}',
            'id',
            'SET NULL',
            'SET NULL',
        );
    }

    public function down(MigrationBuilder $b): void
    {
        // The copied rows are not moved back: `service_order.metadata` still
        // carries every one of them, which is why nothing is lost by dropping
        // this table. That redundancy is the reason reverting is safe here.
        $b->dropTable('{{%service_submission}}');
    }
}