<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Repository\ActivityLogRepository;
use App\Repository\ServiceRepository;
use App\Service\ServiceManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class AdminServicesAction
{
    /** Session key holding the rejected form payload so the view can re-render it. */
    private const FORM_KEY = 'admin_service_form';

    public function __construct(
        private WebViewRenderer $view,
        private ServiceRepository $services,
        private ServiceManager $manager,
        private ActivityLogRepository $logs,
        private SessionInterface $session,
        private UrlGeneratorInterface $url,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            $identity = $request->getAttribute('identity');
            $adminId = $identity !== null ? (int) $identity->id : null;
            $input = (array) $request->getParsedBody();
            $do = (string) ($input['do'] ?? 'create');
            $redirectId = 0;

            if ($do === 'create') {
                [$errors, $values] = $this->validateServiceInput($input, null);
                if ($errors !== []) {
                    $this->reject($errors, ['do' => 'create', 'values' => $values]);
                } else {
                    $this->services->createService($values);
                    $this->logs->create([
                        'user_id' => $adminId,
                        'action' => 'admin.service.created',
                        'description' => "Service '{$values['name']}' created",
                        'metadata' => ['slug' => $values['slug']],
                    ]);
                    $this->session->set('flash_success', 'সার্ভিস তৈরি হয়েছে।');
                }
            } elseif ($do === 'update') {
                $id = (int) ($input['id'] ?? 0);
                $redirectId = $id;
                [$errors, $values] = $this->validateServiceInput($input, $id);
                if ($id <= 0) {
                    $errors[] = 'সার্ভিস আইডি সঠিক নয়।';
                } elseif ($this->services->findServiceById($id) === null) {
                    $errors[] = 'সার্ভিসটি পাওয়া যায়নি।';
                }

                if ($errors !== []) {
                    $this->reject($errors, ['do' => 'update', 'id' => $id, 'values' => $values]);
                } else {
                    $this->services->updateService($id, $values);
                    $this->logs->create([
                        'user_id' => $adminId,
                        'action' => 'admin.service.updated',
                        'description' => "Service '{$values['name']}' updated",
                        'metadata' => ['service_id' => $id],
                    ]);
                    $this->session->set('flash_success', 'সার্ভিস আপডেট হয়েছে।');
                }
            } elseif ($do === 'fields') {
                $id = (int) ($input['id'] ?? 0);
                $redirectId = $id;
                $this->saveFormFields($id, $input, $adminId);
            } elseif ($do === 'toggle') {
                $id = (int) ($input['id'] ?? 0);
                $service = $this->services->findServiceById($id);
                if ($service !== null) {
                    $this->services->updateService($id, [
                        'status' => $service['status'] === 'active' ? 'inactive' : 'active',
                    ]);
                    $this->session->set('flash_success', 'সার্ভিস স্ট্যাটাস পরিবর্তন হয়েছে।');
                }
            } elseif ($do === 'delete') {
                $id = (int) ($input['id'] ?? 0);
                $this->trashService($id, $adminId);
            } elseif ($do === 'restore') {
                $this->restoreService((int) ($input['id'] ?? 0), $adminId);
            } elseif ($do === 'restore_all') {
                $count = $this->services->restoreAll();
                if ($count > 0) {
                    $this->logs->create([
                        'user_id' => $adminId,
                        'action' => 'admin.service.restored',
                        'description' => "All {$count} trashed service(s) restored",
                        'metadata' => ['count' => $count],
                    ]);
                    $this->session->set('flash_success', "{$count}টি সার্ভিস ফিরিয়ে আনা হয়েছে।");
                }
            } elseif ($do === 'purge') {
                $this->purgeService((int) ($input['id'] ?? 0), $adminId);
            }

            $query = $redirectId > 0 ? '?edit=' . $redirectId : '';
            return new \Nyholm\Psr7\Response(302, [
                'Location' => $this->url->generate('admin-services') . $query,
            ]);
        }

        $editId = (int) ($request->getQueryParams()['edit'] ?? 0);
        $form = $this->session->pull(self::FORM_KEY);
        $form = is_array($form) ? $form : null;
        $editId = $form !== null && $form['do'] === 'update' ? (int) $form['id'] : $editId;

        $editService = $editId > 0 ? $this->services->findServiceById($editId) : null;
        $formValues = $form !== null && isset($form['values']) && is_array($form['values']) ? $form['values'] : null;

        return $this->view->render('site/admin/services.twig', [
            'services' => $this->services->allServicesAdmin(),
            'categories' => $this->services->allCategories(false),
            'editService' => $editService,
            'formValues' => $formValues,
            'formDo' => $form !== null ? (string) $form['do'] : null,
            'fieldOptions' => $editService === null ? [] : $this->fieldOptions($editService),
            'trashedCount' => $this->services->countTrashed(),
        ]);
    }

    /**
     * Move a service to the trash. Nothing is lost: the row stays, so the slug
     * stays reserved and every transaction keeps its foreign key.
     */
    private function trashService(int $id, ?int $adminId): void
    {
        $service = $id > 0 ? $this->services->findServiceById($id, true) : null;

        if ($service === null) {
            return;
        }
        if ($service['deleted_at'] !== null) {
            $this->session->set('flash_error', 'সার্ভিসটি ইতিমধ্যে ট্র্যাশে আছে।');
            return;
        }
        if (!$this->services->softDelete($id)) {
            $this->session->set('flash_error', 'সার্ভিসটি মুছে ফেলা যায়নি।');
            return;
        }

        $orders = $this->services->transactionCount($id);
        $this->logs->create([
            'user_id' => $adminId,
            'action' => 'admin.service.trashed',
            'description' => "Service '{$service['name']}' moved to trash ({$orders} transaction(s) kept)",
            'metadata' => ['service_id' => $id, 'slug' => $service['slug']],
        ]);
        $this->session->set('flash_success', $orders > 0
            ? "'{$service['name']}' ট্র্যাশে পাঠানো হয়েছে — {$orders}টি পুরনো লেনদেন রেকর্ড অক্ষত আছে। চাইলে রিস্টোর করা যাবে।"
            : "'{$service['name']}' ট্র্যাশে পাঠানো হয়েছে। চাইলে রিস্টোর করা যাবে।");
    }

    private function restoreService(int $id, ?int $adminId): void
    {
        $service = $id > 0 ? $this->services->findServiceById($id, true) : null;

        if ($service === null || $service['deleted_at'] === null) {
            $this->session->set('flash_error', 'ট্র্যাশে থাকা সার্ভিসটি পাওয়া যায়নি।');
            return;
        }
        if (!$this->services->restore($id)) {
            $this->session->set('flash_error', 'সার্ভিসটি রিস্টোর করা যায়নি।');
            return;
        }

        $this->logs->create([
            'user_id' => $adminId,
            'action' => 'admin.service.restored',
            'description' => "Service '{$service['name']}' restored from trash",
            'metadata' => ['service_id' => $id, 'slug' => $service['slug']],
        ]);
        $this->session->set('flash_success', "'{$service['name']}' ফিরিয়ে আনা হয়েছে।");
    }

    /** Permanently remove a service that is already in the trash. */
    private function purgeService(int $id, ?int $adminId): void
    {
        $service = $id > 0 ? $this->services->findServiceById($id, true) : null;

        if ($service === null || $service['deleted_at'] === null) {
            $this->session->set('flash_error', 'শুধু ট্র্যাশে থাকা সার্ভিস স্থায়ীভাবে মুছা যায় — আগে সেটি মুছে ফেলুন।');
            return;
        }

        $orders = $this->services->purgeService($id);
        $this->logs->create([
            'user_id' => $adminId,
            'action' => 'admin.service.purged',
            'description' => "Service '{$service['name']}' permanently deleted ({$orders} transaction(s) kept)",
            'metadata' => ['service_id' => $id, 'slug' => $service['slug']],
        ]);
        $this->session->set('flash_success', $orders > 0
            ? "সার্ভিস স্থায়ীভাবে মুছা হয়েছে — {$orders}টি পুরনো লেনদেন রেকর্ড সংরক্ষিত আছে।"
            : 'সার্ভিস স্থায়ীভাবে মুছা হয়েছে।');
    }

    /**
     * Validates the create/update payload and returns [errors, values ready for the repository].
     *
     * @param array $input
     * @param int|null $ignoreId Service id to exclude from the duplicate-slug check.
     * @return array{0: string[], 1: array<string, mixed>}
     */
    private function validateServiceInput(array $input, ?int $ignoreId): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        $nameLength = mb_strlen($name);
        if ($name === '') {
            $errors[] = 'সার্ভিসের নাম লিখুন।';
        } elseif ($nameLength > 190) {
            $errors[] = 'সার্ভিসের নাম সর্বোচ্চ ১৯০ অক্ষরের হতে হবে।';
        }

        $slug = $this->slugify((string) ($input['slug'] ?? '') ?: $name);
        $slugLength = mb_strlen($slug);
        if ($slug === '') {
            $errors[] = 'স্লাগ দিন বা নাম থেকে স্লাগ তৈরি হোক।';
        } elseif (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $errors[] = 'স্লাগে শুধু ছোট ইংরেজি অক্ষর, সংখ্যা ও হাইফেন (-) ব্যবহার করুন।';
        } elseif ($slugLength > 190) {
            $errors[] = 'স্লাগ সর্বোচ্চ ১৯০ অক্ষরের হতে হবে।';
        } else {
            // Trashed services are included: their slug is still reserved, so a
            // new service must not be able to take it and break a later restore.
            $existing = $this->services->findServiceBySlug($slug, true);
            if ($existing !== null && $ignoreId !== null && (int) $existing['id'] === $ignoreId) {
                $existing = null;
            }
            if ($existing !== null) {
                $errors[] = $existing['deleted_at'] !== null
                    ? "'{$slug}' স্লাগটি একটি মুছে ফেলা সার্ভিসে আটকে আছে — সেটি রিস্টোর করুন বা অন্য স্লাগ দিন।"
                    : "'{$slug}' স্লাগটি ইতিমধ্যে ব্যবহার হচ্ছে — অন্য স্লাগ দিন।";
            }
        }

        $categoryId = (int) ($input['category_id'] ?? 0);
        if ($categoryId <= 0 || $this->services->findCategoryById($categoryId) === null) {
            $errors[] = 'একটি বৈধ ক্যাটাগরি নির্বাচন করুন।';
        }

        $price = round((float) ($input['price'] ?? 0), 2);
        $rawPrice = trim((string) ($input['price'] ?? ''));
        if ($rawPrice === '' || !is_numeric(str_replace(',', '', $rawPrice))) {
            $errors[] = 'মূল্য একটি সংখ্যা হতে হবে।';
        } elseif ($price < 0) {
            $errors[] = 'মূল্য শূন্য বা তার বেশি হতে হবে — ঋণাত্মক মূল্য দেওয়া যাবে না।';
        } elseif ($price > 9999999.99) {
            $errors[] = 'মূল্য সর্বোচ্চ ৯,৯৯৯,৯৯৯.৯৯ টাকা হতে পারে।';
        }

        $sortOrder = (int) ($input['sort_order'] ?? 0);
        if ($sortOrder < 0) {
            $errors[] = 'সর্ট অর্ডার শূন্য বা তার বেশি হতে হবে।';
        }

        $values = [
            'category_id' => $categoryId,
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string) ($input['description'] ?? '')),
            'icon' => trim((string) ($input['icon'] ?? '')) ?: 'zap',
            'badge' => trim((string) ($input['badge'] ?? '')),
            'price' => max(0.0, $price),
            'sort_order' => max(0, $sortOrder),
            'status' => ($input['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        ];

        return [$errors, $values];
    }

    /**
     * Saves the NID / date-of-birth form field configuration of a service.
     */
    private function saveFormFields(int $id, array $input, ?int $adminId): void
    {
        $service = $id > 0 ? $this->services->findServiceById($id) : null;
        if ($service === null) {
            $this->session->set('flash_error', 'সার্ভিসটি পাওয়া যায়নি।');
            return;
        }

        $known = [];
        foreach ($this->manager->defaultFieldsFor($service) as $field) {
            $known[$field->name] = $field->label;
        }
        if ($known === []) {
            $this->session->set('flash_error', 'এই সার্ভিসের জন্য কোনো ফর্ম ফিল্ড নেই।');
            return;
        }

        $enabled = self::toNameList($input['fields'] ?? []);
        $required = self::toNameList($input['required_fields'] ?? []);

        $errors = [];
        foreach (array_diff($enabled, array_keys($known)) as $unknown) {
            $errors[] = "অজানা ফর্ম ফিল্ড: '{$unknown}'।";
        }
        foreach (array_diff($required, array_keys($known)) as $unknown) {
            $errors[] = "অজানা ফর্ম ফিল্ড: '{$unknown}'।";
        }
        foreach (array_diff($required, $enabled) as $orphan) {
            $errors[] = "'{$orphan}' ফিল্ডটি আবশ্যক হলে সেটি সক্রিয় থাকতে হবে।";
        }
        if ($errors !== []) {
            $this->session->set('flash_error', implode(' ', $errors));
            return;
        }

        $config = [];
        foreach ($enabled as $name) {
            $config[] = ['name' => $name, 'required' => in_array($name, $required, true)];
        }

        $this->services->updateFormFields($id, $config);
        $this->logs->create([
            'user_id' => $adminId,
            'action' => 'admin.service.fields_updated',
            'description' => "Service '{$service['name']}' form fields updated (" . ($config === [] ? 'none' : implode(', ', array_column($config, 'name'))) . ')',
            'metadata' => ['service_id' => $id, 'fields' => $config],
        ]);
        $this->session->set('flash_success', $config === []
            ? 'সার্ভিস ফর্ম থেকে সব ফিল্ড সরানো হয়েছে।'
            : 'ফর্ম ফিল্ড কনফিগারেশন সংরক্ষিত হয়েছে।');
    }

    /**
     * Field rows for the admin UI: every field the service supports, with the
     * currently stored enabled/required state.
     *
     * @return array<int, array{name: string, label: string, type: string, enabled: bool, required: bool}>
     */
    private function fieldOptions(array $service): array
    {
        $config = $this->manager->formFieldConfig($service);
        $state = [];
        foreach ($config ?? [] as $item) {
            $state[$item['name']] = $item['required'];
        }
        $usingDefaults = $config === null;

        $options = [];
        foreach ($this->manager->defaultFieldsFor($service) as $field) {
            $enabled = $usingDefaults || array_key_exists($field->name, $state);
            $options[] = [
                'name' => $field->name,
                'label' => $field->label,
                'type' => $field->type,
                'enabled' => $enabled,
                'required' => $enabled ? ($state[$field->name] ?? $field->required) : false,
            ];
        }

        return $options;
    }

    /**
     * @return string[]
     */
    private static function toNameList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $names = [];
        foreach ($value as $item) {
            $name = trim((string) $item);
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * @param string[] $errors
     * @param array<string, mixed> $form
     */
    private function reject(array $errors, array $form): void
    {
        $this->session->set('flash_error', implode(' ', $errors));
        $this->session->set(self::FORM_KEY, $form);
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim($text, '-');
    }
}
