<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\ServiceRepository;
use App\Service\Api;
use App\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminCategoriesApiAction
{
    public function __construct(
        private ServiceRepository $services,
        private PermissionService $permissions,
        private ActivityLogRepository $logs,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$this->permissions->canAccessRoute($identity, 'admin-categories')) {
            return Api::forbidden();
        }

        if ($request->getMethod() === 'POST') {
            return $this->handlePost((array) $request->getParsedBody(), $identity);
        }

        return Api::ok([
            'rows' => $this->services->allCategories(false),
        ]);
    }

    private function handlePost(array $input, Identity $identity): ResponseInterface
    {
        $adminId = (int) $identity->id;
        $do = (string) ($input['do'] ?? 'create');
        $permissionRoute = match ($do) {
            'create' => 'admin-categories-create',
            'update', 'toggle' => 'admin-categories-update',
            'delete' => 'admin-categories-delete',
            default => null,
        };

        if ($permissionRoute === null) {
            return Api::fail('অজানা অ্যাকশন।', [], 400);
        }
        if (!$this->permissions->canAccessRoute($identity, $permissionRoute)) {
            return Api::forbidden();
        }

        if ($do === 'create') {
            $name = trim((string) ($input['name'] ?? ''));
            $slug = $this->slugify((string) ($input['slug'] ?? '') ?: $name);
            if ($name === '' || $slug === '') {
                return Api::fail('নাম এবং স্লাগ প্রয়োজন।');
            }
            $this->services->createCategory([
                'name' => $name,
                'slug' => $slug,
                'icon' => (string) ($input['icon'] ?? 'grid'),
                'description' => (string) ($input['description'] ?? ''),
                'accent' => (string) ($input['accent'] ?? 'blue'),
                'sort_order' => (int) ($input['sort_order'] ?? 0),
            ]);
            $this->logs->create([
                'user_id' => $adminId,
                'action' => 'admin.category.created',
                'description' => "Category '{$name}' created",
                'metadata' => ['slug' => $slug],
            ]);
            return Api::ok(['message' => 'ক্যাটাগরি তৈরি হয়েছে।']);
        } elseif ($do === 'update') {
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                return Api::fail('আইডি প্রয়োজন।');
            }
            $this->services->updateCategory($id, [
                'name' => trim((string) ($input['name'] ?? '')),
                'icon' => (string) ($input['icon'] ?? 'grid'),
                'description' => (string) ($input['description'] ?? ''),
                'accent' => (string) ($input['accent'] ?? 'blue'),
                'sort_order' => (int) ($input['sort_order'] ?? 0),
                'status' => ($input['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ]);
            $this->logs->create([
                'user_id' => $adminId,
                'action' => 'admin.category.updated',
                'description' => "Category #{$id} updated",
                'metadata' => ['category_id' => $id],
            ]);
            return Api::ok(['message' => 'ক্যাটাগরি আপডেট হয়েছে।']);
        } elseif ($do === 'toggle') {
            $id = (int) ($input['id'] ?? 0);
            foreach ($this->services->allCategories(false) as $c) {
                if ((int) $c['id'] === $id) {
                    $this->services->updateCategory($id, [
                        'status' => $c['status'] === 'active' ? 'inactive' : 'active',
                    ]);
                    break;
                }
            }
            return Api::ok(['message' => 'ক্যাটাগরি স্ট্যাটাস পরিবর্তন হয়েছে।']);
        } elseif ($do === 'delete') {
            $id = (int) ($input['id'] ?? 0);
            $categories = $this->services->allCategories(false);
            $category = null;
            foreach ($categories as $c) {
                if ((int) $c['id'] === $id) {
                    $category = $c;
                    break;
                }
            }
            $this->services->deleteCategory($id);
            if ($category !== null) {
                $this->logs->create([
                    'user_id' => $adminId,
                    'action' => 'admin.category.deleted',
                    'description' => "Category '{$category['name']}' deleted",
                    'metadata' => ['category_id' => $id],
                ]);
            }
            return Api::ok(['message' => 'ক্যাটাগরি মুছে ফেলা হয়েছে।']);
        }

        return Api::fail('অজানা অ্যাকশন।');
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? $value;
        return trim($value, '-');
    }
}
