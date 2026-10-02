<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

final class ServiceRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    // ---- Categories -------------------------------------------------------

    public function findCategoryBySlug(string $slug): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%service_category}} WHERE [[slug]] = :slug LIMIT 1')
            ->bindValue(':slug', $slug)
            ->queryOne();
        return $row === false ? null : $row;
    }

    public function allCategories(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM {{%service_category}}';
        if ($activeOnly) {
            $sql .= " WHERE [[status]] = 'active'";
        }
        $sql .= ' ORDER BY [[sort_order]] ASC, [[id]] ASC';
        return $this->db->createCommand($sql)->queryAll();
    }

    public function createCategory(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%service_category}}', [
            'name' => $row['name'],
            'slug' => $row['slug'],
            'icon' => $row['icon'] ?? null,
            'description' => $row['description'] ?? null,
            'accent' => $row['accent'] ?? 'auto',
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'status' => $row['status'] ?? 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    public function updateCategory(int $id, array $values): void
    {
        $values['updated_at'] = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('{{%service_category}}', $values, ['id' => $id])->execute();
    }

    public function deleteCategory(int $id): void
    {
        $this->db->createCommand()->delete('{{%service_category}}', ['id' => $id])->execute();
    }

    // ---- Services ---------------------------------------------------------

    /**
     * Look a service up by slug. Trashed services are hidden unless
     * $withDeleted is set — the admin slug check needs to see them so a new
     * service cannot steal a slug that a restorable service still holds.
     */
    public function findServiceBySlug(string $slug, bool $withDeleted = false): ?array
    {
        $sql = 'SELECT * FROM {{%service}} WHERE [[slug]] = :slug';
        if (!$withDeleted) {
            $sql .= ' AND [[deleted_at]] IS NULL';
        }
        $row = $this->db
            ->createCommand($sql . ' LIMIT 1')
            ->bindValue(':slug', $slug)
            ->queryOne();
        return $row === false ? null : $row;
    }

    public function findCategoryById(int $id): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%service_category}} WHERE [[id]] = :id LIMIT 1')
            ->bindValue(':id', $id)
            ->queryOne();
        return $row === false ? null : $row;
    }

    public function findServiceById(int $id, bool $withDeleted = false): ?array
    {
        $sql = 'SELECT * FROM {{%service}} WHERE [[id]] = :id';
        if (!$withDeleted) {
            $sql .= ' AND [[deleted_at]] IS NULL';
        }
        $row = $this->db
            ->createCommand($sql . ' LIMIT 1')
            ->bindValue(':id', $id)
            ->queryOne();
        return $row === false ? null : $row;
    }

    public function servicesByCategory(?int $categoryId = null): array
    {
        if ($categoryId === null) {
            return $this->db
                ->createCommand("SELECT * FROM {{%service}} WHERE [[status]] = 'active' AND [[deleted_at]] IS NULL ORDER BY [[sort_order]] ASC, [[id]] ASC")
                ->queryAll();
        }
        return $this->db
            ->createCommand("SELECT * FROM {{%service}} WHERE [[category_id]] = :cid AND [[status]] = 'active' AND [[deleted_at]] IS NULL ORDER BY [[sort_order]] ASC, [[id]] ASC")
            ->bindValue(':cid', $categoryId)
            ->queryAll();
    }

    /**
     * Every service regardless of status, trashed ones included — for the admin
     * list, which shows both so deleted rows can be restored.
     */
    public function allServicesAdmin(): array
    {
        return $this->db
            ->createCommand('SELECT * FROM {{%service}} ORDER BY [[deleted_at]] IS NOT NULL ASC, [[sort_order]] ASC, [[id]] ASC')
            ->queryAll();
    }

    public function searchServices(string $term, ?int $categoryId = null): array
    {
        $sql = "SELECT * FROM {{%service}} WHERE [[status]] = 'active' AND [[deleted_at]] IS NULL AND ([[name]] LIKE :t OR [[description]] LIKE :t)";
        $params = [':t' => "%{$term}%"];
        if ($categoryId !== null) {
            $sql .= ' AND [[category_id]] = :cid';
            $params[':cid'] = $categoryId;
        }
        $sql .= ' ORDER BY [[sort_order]] ASC, [[id]] ASC';
        return $this->db->createCommand($sql)->bindValues($params)->queryAll();
    }

    public function createService(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->createCommand()->insert('{{%service}}', [
            'category_id' => (int) $row['category_id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'description' => $row['description'] ?? null,
            'icon' => $row['icon'] ?? null,
            'badge' => $row['badge'] ?? null,
            'service_type' => $row['service_type'] ?? 'mock',
            'form_fields' => isset($row['form_fields']) && $row['form_fields'] !== null
                ? (is_string($row['form_fields']) ? $row['form_fields'] : json_encode($row['form_fields'], JSON_THROW_ON_ERROR))
                : null,
            'price' => $row['price'] ?? 0,
            'variants' => isset($row['variants']) && $row['variants'] !== null
                ? (is_string($row['variants']) ? $row['variants'] : json_encode($row['variants'], JSON_THROW_ON_ERROR))
                : null,
            'rules' => $row['rules'] ?? null,
            'status' => $row['status'] ?? 'active',
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        return (int) $this->db->getLastInsertID();
    }

    /**
     * Store the per-service purchasable variants (JSON list of {label, price})
     * and ordering rules/instructions text. Pass null for either to clear it.
     */
    public function updateVariantsAndRules(int $id, ?array $variants, ?string $rules): void
    {
        $this->db->createCommand()->update('{{%service}}', [
            'variants' => $variants === null ? null : json_encode($variants, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'rules' => $rules === null ? null : $rules,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id])->execute();
    }

    public function updateService(int $id, array $values): void
    {
        $values['updated_at'] = date('Y-m-d H:i:s');
        $this->db->createCommand()->update('{{%service}}', $values, ['id' => $id])->execute();
    }

    /**
     * Store the per-service form field configuration as a JSON list of
     * {name, required} objects. Pass null to fall back to provider defaults.
     */
    public function updateFormFields(int $id, ?array $fields): void
    {
        $this->db->createCommand()->update(
            '{{%service}}',
            [
                'form_fields' => $fields === null ? null : json_encode($fields, JSON_THROW_ON_ERROR),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            ['id' => $id],
        )->execute();
    }

    // ---- Soft delete / restore -------------------------------------------

    /**
     * Move a service to the trash. The row stays, so its slug reservation and
     * transaction history survive a restore. Returns false if it was already
     * trashed or does not exist.
     */
    public function softDelete(int $id): bool
    {
        return $this->db
            ->createCommand()
            ->update(
                '{{%service}}',
                ['deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id, 'deleted_at' => null],
            )
            ->execute() > 0;
    }

    /**
     * Bring a trashed service back. Returns false if it was not trashed.
     *
     * The condition is a raw SQL string on purpose: the array condition
     * ['deleted_at' => ['<>', null]] does NOT compile to IS NOT NULL, it
     * expands to "deleted_at = '<>' OR deleted_at IS NULL".
     */
    public function restore(int $id): bool
    {
        return $this->db
            ->createCommand()
            ->update(
                '{{%service}}',
                ['deleted_at' => null, 'updated_at' => date('Y-m-d H:i:s')],
                '[[id]] = :id AND [[deleted_at]] IS NOT NULL',
            )
            ->bindValue(':id', $id)
            ->execute() > 0;
    }

    /** Empty the trash. Returns how many services were restored. */
    public function restoreAll(): int
    {
        return $this->db
            ->createCommand()
            ->update('{{%service}}', ['deleted_at' => null], '[[deleted_at]] IS NOT NULL')
            ->execute();
    }

    public function countTrashed(): int
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%service}} WHERE [[deleted_at]] IS NOT NULL')
            ->queryScalar();
    }

    /** How many transactions reference this service. */
    public function transactionCount(int $serviceId): int
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%transaction}} WHERE [[service_id]] = :id')
            ->bindValue(':id', $serviceId)
            ->queryScalar();
    }

    /**
     * Permanently remove an already-trashed service, preserving transaction
     * history (FK-safe): old transaction rows are kept by detaching them
     * (service_id → NULL). Returns how many transactions were kept.
     */
    public function purgeService(int $id): int
    {
        $service = $this->findServiceById($id, true);
        if ($service === null || $service['deleted_at'] === null) {
            return 0;
        }

        $kept = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%transaction}} WHERE [[service_id]] = :id')
            ->bindValue(':id', $id)
            ->queryScalar();

        $this->db->createCommand()
            ->update('{{%transaction}}', ['service_id' => null], ['service_id' => $id])
            ->execute();
        $this->db->createCommand()->delete('{{%service}}', ['id' => $id])->execute();

        return $kept;
    }
}
