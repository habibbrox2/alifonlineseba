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
        $trashed = ($params['trashed'] ?? '') === 'only' || ($params['trashed'] ?? '') === 'all';
        return Api::ok([
            'rows' => $this->services->allServices($trashed),
            'categories' => $this->services->allCategories(false),
        ]);
    }

    private function handlePost(array $input, Identity $identity): ResponseInterface
    {
        $adminId = (int) $identity->id;
        $do = (string) ($input['do'] ?? 'create');

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
        } elseif ($do === 'update') {
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
        } elseif ($do === 'toggle') {
            $id = (int) ($input['id'] ?? 0);
            $service = $this->services->findServiceById($id);
            if ($service !== null) {
                $this->services->updateService($id, [
                    'status' => $service['status'] === 'active' ? 'inactive' : 'active',
                ]);
            }
            return Api::ok(['message' => 'সার্ভিস স্ট্যাটাস পরিবর্তন হয়েছে।']);
        } elseif ($do === 'delete') {
            $id = (int) ($input['id'] ?? 0);
            $this->services->deleteService($id);
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
