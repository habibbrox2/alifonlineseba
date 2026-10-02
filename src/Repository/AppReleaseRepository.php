<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * The APK manifest read by /app, the install banner and the in-app update check.
 *
 * Only the *metadata* lives in the database. The APK bytes sit on disk outside
 * the web root (`AppReleaseService`), which is why `apk_path` is stored
 * relative — a row can never be turned into a path that reaches somewhere else
 * on the filesystem by editing a string.
 *
 * ## The one-published-row invariant
 *
 * `is_published` has no unique index enforcing "at most one". That is
 * deliberate: publishing is *unpublish the old row and publish the new one*,
 * and on MySQL a partial unique index is not available. Doing it as two
 * statements would leave a window where either zero or two rows are published,
 * and a reader landing in that window would serve a random APK. So the switch
 * happens inside a transaction (see `publish()`), and readers always order by
 * `version_code` so the newest wins even if the invariant is ever broken by a
 * hand-edited row.
 */
final class AppReleaseRepository
{
    private const COLUMNS = '[[id]], [[version_code]], [[version_name]], [[apk_path]], [[apk_size]],'
        . ' [[sha256]], [[min_version_code]], [[release_notes]], [[is_published]], [[published_at]]';

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * The release a visitor should be offered, or null when none is published.
     *
     * @return array<string, mixed>|null
     */
    public function published(): ?array
    {
        $row = $this->db
            ->createCommand(
                'SELECT ' . self::COLUMNS . ' FROM {{%app_release}} WHERE [[is_published]] = 1'
                . ' ORDER BY [[version_code]] DESC LIMIT 1'
            )
            ->queryOne();

        return $row === false ? null : (array) $row;
    }

    /**
     * The newest row regardless of publication state — what the admin form
     * shows, so a staged-but-unpublished release is still visible.
     *
     * @return array<string, mixed>|null
     */
    public function latest(): ?array
    {
        $row = $this->db
            ->createCommand(
                'SELECT ' . self::COLUMNS . ' FROM {{%app_release}} ORDER BY [[version_code]] DESC LIMIT 1'
            )
            ->queryOne();

        return $row === false ? null : (array) $row;
    }

    /**
     * Every release, newest first — the admin release list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $rows = $this->db
            ->createCommand('SELECT ' . self::COLUMNS . ' FROM {{%app_release}} ORDER BY [[version_code]] DESC')
            ->queryAll();

        return array_map(static fn (array $row): array => $row, $rows);
    }

    /**
     * The floor below which a client must update, taken from the published row.
     *
     * Returns 0 when nothing is published, which compares as "always current"
     * to the app — an unpublished release must not brick an installed app that
     * happens to be old.
     */
    public function minVersionCode(): int
    {
        $row = $this->db
            ->createCommand('SELECT [[min_version_code]] FROM {{%app_release}} WHERE [[is_published]] = 1'
                . ' ORDER BY [[version_code]] DESC LIMIT 1')
            ->queryOne();

        return $row === false ? 0 : (int) $row['min_version_code'];
    }

    /**
     * Publish one release, demoting whatever was published before.
     *
     * The demotion and the promotion are one transaction on purpose — see the
     * class note about the missing partial unique index.
     *
     * @param array<string, mixed> $data {version_code, version_name, apk_path,
     *   apk_size, sha256, min_version_code, release_notes}
     * @return int The release id.
     */
    public function publish(array $data): int
    {
        $versionCode = (int) $data['version_code'];
        $now = date('Y-m-d H:i:s');

        return (int) $this->db->transaction(function () use ($data, $versionCode, $now): int {
            $this->db->createCommand(
                'UPDATE {{%app_release}} SET [[is_published]] = 0, [[updated_at]] = :now WHERE [[is_published]] = 1'
            )->bindValue(':now', $now)->execute();

            $existing = $this->db
                ->createCommand('SELECT [[id]] FROM {{%app_release}} WHERE [[version_code]] = :v LIMIT 1')
                ->bindValue(':v', $versionCode)
                ->queryOne();

            $row = [
                'version_code' => $versionCode,
                'version_name' => (string) $data['version_name'],
                'apk_path' => (string) $data['apk_path'],
                'apk_size' => (int) $data['apk_size'],
                'sha256' => (string) $data['sha256'],
                'min_version_code' => (int) ($data['min_version_code'] ?? 0),
                'release_notes' => $data['release_notes'] ?? null,
                'is_published' => 1,
                'published_at' => $now,
                'updated_at' => $now,
            ];

            if ($existing === false) {
                $row['created_at'] = $now;
                $this->db->createCommand()->insert('{{%app_release}}', $row)->execute();
                return (int) $this->db->getLastInsertID();
            }

            $this->db->createCommand()
                ->update('{{%app_release}}', $row, ['id' => (int) $existing['id']])
                ->execute();

            return (int) $existing['id'];
        });
    }

    /** Withdraw the current release: the /app page falls back to "coming soon". */
    public function unpublish(int $id): bool
    {
        return $this->db
            ->createCommand()
            ->update(
                '{{%app_release}}',
                ['is_published' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id, 'is_published' => 1],
            )
            ->execute() > 0;
    }
}
