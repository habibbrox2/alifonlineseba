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

final readonly class AdminServicesApiAction
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
        if (!$this->permissions->canAccessRoute($identity, 'admin-services')) {
            return Api::forbidden();
        }

        if ($request->getMethod() === 'POST') {
            return $this->handlePost((array) $request->getParsedBody(), $identity);
        }

        $params = $request->getQueryParams();
        $trashed = (string) ($params['trashed'] ?? ServiceRepository::DELETED_EXCLUDE);
        if (!in_array($trashed, [ServiceRepository::DELETED_EXCLUDE, ServiceRepository::DELETED_ONLY, ServiceRepository::DELETED_ALL], true)) {
            $trashed = ServiceRepository::DELETED_EXCLUDE;
        }

        $filter = [
            'trashed' => $trashed,
            'q' => trim((string) ($params['q'] ?? '')),
            'category' => (string) ($params['category'] ?? ''),
            'status' => (string) ($params['status'] ?? ''),
        ];

        $filtered = $this->services->adminFiltered($filter);

        return Api::ok([
            'rows' => $filtered['rows'],
            'categories' => $this->services->allCategories(false),
            'total' => $filtered['total'],
            'trashedCount' => $this->services->countTrashed(),
        ]);
    }

    private function handlePost(array $input, Identity $identity): ResponseInterface
    {
        $adminId = (int) $identity->id;
        $do = (string) ($input['do'] ?? 'create');
        $permissionRoute = match ($do) {
            'create' => 'admin-services-create',
            'update', 'toggle' => 'admin-services-update',
            'delete' => 'admin-services-delete',
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
            $this->services->createService([
                'category_id' => $input['category_id'] !== '' && $input['category_id'] !== null ? (int)$input['category_id'] : null,
                'name' => $name,
                'slug' => $slug,
                'icon' => (string) ($input['icon'] ?? 'wrench'),
                'description' => (string) ($input['description'] ?? ''),
                'price' => (string) ($input['price'] ?? '0'),
                'unit' => (string) ($input['unit'] ?? ''),
                'sort_order' => (int) ($input['sort_order'] ?? 0),
                'status' => ($input['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ]);
            $this->logs->create([
                'user_id' => $adminId,
                'action' => 'admin.service.created',
                'description' => "Service '{$name}' created",
                'metadata' => ['slug' => $slug],
            ]);
            return Api::ok(['message' => 'সার্ভিস তৈরি হয়েছে।']);
        }

        if ($do === 'update') {
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                return Api::fail('আইডি প্রয়োজন।');
            }
            $this->services->updateService($id, [
                'category_id' => $input['category_id'] !== '' && $input['category_id'] !== null ? (int)$input['category_id'] : null,
                'name' => trim((string) ($input['name'] ?? '')),
                'icon' => (string) ($input['icon'] ?? 'wrench'),
                'description' => (string) ($input['description'] ?? ''),
                'price' => (string) ($input['price'] ?? '0'),
                'unit' => (string) ($input['unit'] ?? ''),
                'sort_order' => (int) ($input['sort_order'] ?? 0),
                'status' => ($input['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ]);
            $this->logs->create([
                'user_id' => $adminId,
                'action' => 'admin.service.updated',
                'description' => "Service #{$id} updated",
                'metadata' => ['service_id' => $id],
            ]);
            return Api::ok(['message' => 'সার্ভিস আপডেট হয়েছে।']);
        }

        if ($do === 'toggle') {
            $id = (int) ($input['id'] ?? 0);
            $service = $this->services->findServiceById($id);
            if ($service !== null) {
                $this->services->updateService($id, [
                    'status' => $service['status'] === 'active' ? 'inactive' : 'active',
                ]);
            }
            return Api::ok(['message' => 'সার্ভিস স্ট্যাটাস পরিবর্তন হয়েছে।']);
        }

        if ($do === 'delete') {
            $id = (int) ($input['id'] ?? 0);
            $this->services->softDelete($id);
            $this->logs->create([
                'user_id' => $adminId,
                'action' => 'admin.service.deleted',
                'description' => "Service #{$id} deleted",
                'metadata' => ['service_id' => $id],
            ]);
            return Api::ok(['message' => 'সার্ভিস মুছে ফেলা হয়েছে।']);
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
