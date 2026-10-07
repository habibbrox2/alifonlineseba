<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * An avatar image on the account itself.
 *
 * Users can upload a profile picture from the profile edit page, and it is
 * shown on the dashboard navbar and the profile page. The bytes live outside
 * the web root (in `web/image-uploads/`, the same bucket the ordinary image
 * upload storage uses), so this column only holds the *relative path* — the
 * serving route (`/profile/avatar/{id}`) is the only way the bytes ever leave
 * the server, and it checks ownership.
 *
 * `varchar(255) NULL`, because:
 *
 *  - not every account has an avatar (many will use the default initials chip),
 *    and NULL is what "no avatar" reads as everywhere;
 *  - the path is short — it is `bucket-hash/filename.ext`, rarely more than a
 *    few dozen characters.
 *
 * No UNIQUE constraint: an avatar is owned by exactly one user and the owning
 * user is the only one who can change it, so two accounts can never claim the
 * same path. The path is hashed by `ImageUploadStorage`, so it is already not
 * guessable.
 */
final class M261007000000_AddAvatarToUser implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn('{{%user}}', 'avatar', 'string NULL');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn('{{%user}}', 'avatar');
    }
}
