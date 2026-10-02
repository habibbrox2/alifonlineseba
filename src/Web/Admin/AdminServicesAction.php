<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Repository\ActivityLogRepository;
use App\Repository\ServiceRepository;
use App\Service\ServiceManager;
use App\ServiceProvider\ServiceField;
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
            } elseif ($do === 'fields_reset') {
                $id = (int) ($input['id'] ?? 0);
                $redirectId = $id;
                $this->resetFormFields($id, $adminId);
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
            'fvRules' => $this->rulesForEditor($formValues, $editService),
            'fvVariants' => $this->variantsForEditor($formValues, $editService),
            'fieldOptions' => $editService === null ? [] : $this->fieldOptions($editService),
            'fieldTypes' => ServiceField::TYPES,
            'usingFieldDefaults' => $editService === null || $this->manager->formFieldConfig($editService) === null,
            'trashedCount' => $this->services->countTrashed(),
        ]);
    }

    /** Rules text for the editor: submitted value wins, else the stored one. */
    private function rulesForEditor(?array $formValues, ?array $editService): string
    {
        if ($formValues !== null && array_key_exists('rules', $formValues)) {
            return (string) $formValues['rules'];
        }
        return (string) ($editService['rules'] ?? '');
    }

    /**
     * Variant rows for the editor: submitted values win, else the stored JSON.
     * Always at least three blank rows so the admin can start typing right away.
     *
     * @return array<int, array{label: string, price: string}>
     */
    private function variantsForEditor(?array $formValues, ?array $editService): array
    {
        $rows = [];
        $source = null;
        if ($formValues !== null && array_key_exists('variants', $formValues)) {
            $source = json_decode((string) ($formValues['variants'] ?? ''), true);
        } elseif ($editService !== null) {
            $raw = $editService['variants'] ?? null;
            $source = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;
        }
        if (is_array($source)) {
            foreach ($source as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $label = trim((string) ($item['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $rows[] = ['label' => $label, 'price' => (string) ($item['price'] ?? '')];
            }
        }
        while (count($rows) < 3) {
            $rows[] = ['label' => '', 'price' => ''];
        }
        return $rows;
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

        // Per-service variants: rows of {label, price}; blank labels dropped.
        $variants = $this->parseVariantsInput($input, $errors);
        $values['variants'] = $variants === null ? null : json_encode($variants, JSON_UNESCAPED_UNICODE);
        // Ordering rules/instructions (newline separated); empty string -> NULL.
        $rules = trim((string) ($input['rules'] ?? ''));
        $values['rules'] = $rules !== '' ? $rules : null;

        return [$errors, $values];
    }

    /**
     * Variant rows from the admin form: parallel arrays variant_label[N] /
     * variant_price[N]. Labels are kept in order; blank rows are dropped and
     * an empty result is stored as NULL (no selector on the order page).
     *
     * @return array|null JSON-ready list of {label, price} or null when none
     */
    private function parseVariantsInput(array $input, array &$errors): ?array
    {
        $labels = (array) ($input['variant_label'] ?? []);
        $prices = (array) ($input['variant_price'] ?? []);
        $variants = [];
        foreach ($labels as $i => $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            $price = (float) str_replace(',', '', (string) ($prices[$i] ?? '0'));
            if ($price < 0) {
                $errors[] = "ভ্যারিয়েন্ট '{$label}'-এর মূল্য ঋণাত্মক হতে পারে না।";
                continue;
            }
            $variants[] = ['label' => $label, 'price' => $price];
        }
        return $variants === [] ? null : $variants;
    }

    /**
     * Saves the form field configuration of a service: which provider fields are
     * shown, which admin-defined custom fields exist, and which are required.
     */
    private function saveFormFields(int $id, array $input, ?int $adminId): void
    {
        $service = $id > 0 ? $this->services->findServiceById($id) : null;
        if ($service === null) {
            $this->session->set('flash_error', 'সার্ভিসটি পাওয়া যায়নি।');
            return;
        }

        $result = self::buildFormFieldConfig(
            $input,
            $this->manager->defaultFieldsFor($service),
            $this->manager->customFieldsFor($service),
        );
        if ($result['errors'] !== []) {
            $this->session->set('flash_error', implode(' ', $result['errors']));
            return;
        }

        $config = $result['config'];
        $this->services->updateFormFields($id, $config);
        $this->logs->create([
            'user_id' => $adminId,
            'action' => 'admin.service.fields_updated',
            'description' => "Service '{$service['name']}' form fields updated (" . ($config === [] ? 'none' : implode(', ', array_column($config, 'name'))) . ')',
            'metadata' => ['service_id' => $id, 'fields' => $config],
        ]);

        $notes = [];
        if ($result['added'] !== []) {
            $notes[] = count($result['added']) . 'টি নতুন ফিল্ড যোগ হয়েছে';
        }
        if ($result['removed'] !== []) {
            $notes[] = count($result['removed']) . 'টি কাস্টম ফিল্ড মুছে ফেলা হয়েছে';
        }
        $suffix = $notes === [] ? '' : ' (' . implode(', ', $notes) . ')';

        $this->session->set('flash_success', ($config === []
            ? 'সার্ভিস ফর্ম থেকে সব ফিল্ড সরানো হয়েছে।'
            : 'ফর্ম ফিল্ড কনফিগারেশন সংরক্ষিত হয়েছে।') . $suffix);
    }

    /**
     * Turns the field-configuration form into the list to store.
     *
     * Pure on purpose: what gets saved depends only on what the admin posted
     * and on what the service already had, so the whole add / edit / remove
     * cycle can be exercised without a request, a session or the database.
     *
     * Custom field definitions are always rewritten, not only the visible ones:
     * a field that is switched off keeps its label, type and help so it can be
     * switched back on later. Only an explicit "remove" actually deletes.
     *
     * @param ServiceField[] $providerFields
     * @param ServiceField[] $existingCustoms
     * @return array{config: array<int, array<string, mixed>>, errors: string[], added: string[], removed: string[]}
     */
    public static function buildFormFieldConfig(array $input, array $providerFields, array $existingCustoms): array
    {
        $errors = [];

        $customs = [];
        foreach ($existingCustoms as $field) {
            $customs[$field->name] = $field;
        }

        // 1. Delete the custom fields the admin ticked for removal.
        $removed = [];
        foreach (self::toNameList($input['remove_custom'] ?? []) as $name) {
            if (isset($customs[$name])) {
                unset($customs[$name]);
                $removed[] = $name;
                continue;
            }
            $errors[] = "মুছে ফেলার অনুরোধ করা কাস্টম ফিল্ডটি পাওয়া যায়নি: '{$name}'।";
        }

        // 2. Apply the inline edits to the custom fields that survived.
        //    An absent key means "not posted", so the stored value survives; only
        //    a key the admin actually sent can blank a label out.
        $labels = self::namedList($input, 'custom_label');
        $types = self::namedList($input, 'custom_type');
        $placeholders = self::namedList($input, 'custom_placeholder');
        $helps = self::namedList($input, 'custom_help');
        foreach ($customs as $name => $field) {
            $label = array_key_exists($name, $labels) ? self::text($labels[$name], 120) : $field->label;
            if ($label === '') {
                $errors[] = "'{$name}' ফিল্ডের লেবেল খালি রাখা যাবে না।";
                continue;
            }
            $customs[$name] = new ServiceField(
                $name,
                $label,
                array_key_exists($name, $types) && is_scalar($types[$name])
                    ? ServiceField::normaliseType($types[$name])
                    : $field->type,
                $field->required,
                array_key_exists($name, $placeholders) ? self::text($placeholders[$name], 120) : $field->placeholder,
                array_key_exists($name, $helps) ? self::text($helps[$name], 190) : $field->help,
            );
        }

        // 3. Add the brand new custom fields; each lands switched on.
        $enabled = self::toNameList($input['fields'] ?? []);
        $required = self::toNameList($input['required_fields'] ?? []);
        [$newFields, $newErrors] = self::newCustomFields($input['custom_new'] ?? []);
        $errors = array_merge($errors, $newErrors);

        $added = [];
        foreach ($newFields as $field) {
            if (self::findField($providerFields, $field->name) !== null) {
                $errors[] = "'{$field->name}' নামটি আগে থেকেই একটি ফিল্ডে ব্যবহৃত হচ্ছে।";
                continue;
            }
            if (isset($customs[$field->name])) {
                $errors[] = "'{$field->name}' নামে আরেকটি কাস্টম ফিল্ড আছে।";
                continue;
            }
            $customs[$field->name] = $field;
            $added[] = $field->name;
            $enabled[] = $field->name;
            if ($field->required) {
                $required[] = $field->name;
            }
        }

        // 4. The enabled/required checkboxes may only name fields that exist.
        $known = [];
        foreach ($providerFields as $field) {
            $known[$field->name] = true;
        }
        foreach ($customs as $name => $field) {
            $known[$name] = true;
        }
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
            return ['config' => [], 'errors' => $errors, 'added' => [], 'removed' => $removed];
        }

        // 5. Provider fields first, then the custom ones in the order they were added.
        $config = [];
        foreach ($enabled as $name) {
            if (self::findField($providerFields, $name) !== null) {
                $config[] = ['name' => $name, 'required' => in_array($name, $required, true)];
            }
        }
        foreach ($customs as $name => $field) {
            $config[] = [
                'name' => $field->name,
                'label' => $field->label,
                'type' => $field->type,
                'required' => in_array($name, $required, true),
                'placeholder' => $field->placeholder,
                'help' => $field->help,
                'enabled' => in_array($name, $enabled, true),
            ];
        }

        return ['config' => $config, 'errors' => [], 'added' => $added, 'removed' => $removed];
    }

    /**
     * Throws away the stored configuration so the provider defaults apply again
     * and every custom field disappears.
     */
    private function resetFormFields(int $id, ?int $adminId): void
    {
        $service = $id > 0 ? $this->services->findServiceById($id) : null;
        if ($service === null) {
            $this->session->set('flash_error', 'সার্ভিসটি পাওয়া যায়নি।');
            return;
        }

        $this->services->updateFormFields($id, null);
        $this->logs->create([
            'user_id' => $adminId,
            'action' => 'admin.service.fields_reset',
            'description' => "Service '{$service['name']}' form fields reset to provider defaults",
            'metadata' => ['service_id' => $id],
        ]);
        $this->session->set('flash_success', 'ফর্ম ফিল্ড কনফিগারেশন ডিফল্টে ফিরিয়ে আনা হয়েছে — সব কাস্টম ফিল্ড মুছে গেছে।');
    }

    /**
     * Field rows for the admin UI: every field the service supports, with the
     * currently stored enabled/required state.
     *
     * @return array<int, array{name: string, label: string, type: string, enabled: bool, required: bool, custom: bool, placeholder: string, help: string}>
     */
    private function fieldOptions(array $service): array
    {
        return self::buildFieldOptions(
            $this->manager->defaultFieldsFor($service),
            $this->manager->formFieldConfig($service),
        );
    }

    /**
     * Provider fields come first, then the admin-defined ones in the order they
     * were added. A null config means the provider defaults still apply, so
     * every one of them is on.
     *
     * @param ServiceField[] $defaults
     * @param array<int, array<string, mixed>>|null $config
     * @return array<int, array{name: string, label: string, type: string, enabled: bool, required: bool, custom: bool, placeholder: string, help: string}>
     */
    public static function buildFieldOptions(array $defaults, ?array $config): array
    {
        $usingDefaults = $config === null;

        $state = [];
        foreach ($config ?? [] as $item) {
            $state[(string) $item['name']] = [
                'required' => (bool) $item['required'],
                'enabled' => (bool) ($item['enabled'] ?? true),
            ];
        }

        $options = [];
        foreach ($defaults as $field) {
            $enabled = $usingDefaults || array_key_exists($field->name, $state);
            $options[] = self::optionRow(
                $field,
                $enabled,
                $state[$field->name]['required'] ?? $field->required,
                false,
            );
        }
        foreach (self::customFieldsOf($config ?? []) as $field) {
            $options[] = self::optionRow(
                $field,
                $state[$field->name]['enabled'] ?? false,
                $state[$field->name]['required'] ?? $field->required,
                true,
            );
        }

        return $options;
    }

    /**
     * @param array<int, array<string, mixed>> $config
     * @return ServiceField[]
     */
    private static function customFieldsOf(array $config): array
    {
        $customs = [];
        foreach ($config as $item) {
            $field = ServiceField::fromConfig($item);
            if ($field !== null) {
                $customs[] = $field;
            }
        }

        return $customs;
    }

    /**
     * @param ServiceField[] $fields
     */
    private static function findField(array $fields, string $name): ?ServiceField
    {
        foreach ($fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return array{name: string, label: string, type: string, enabled: bool, required: bool, custom: bool, placeholder: string, help: string}
     */
    private static function optionRow(ServiceField $field, bool $enabled, bool $required, bool $custom): array
    {
        return [
            'name' => $field->name,
            'label' => $field->label,
            'type' => $field->type,
            'enabled' => $enabled,
            'required' => $enabled && $required,
            'custom' => $custom,
            'placeholder' => $field->placeholder,
            'help' => $field->help,
        ];
    }

    /**
     * Reads the "নতুন ফিল্ড" repeater rows. A row the admin never touched is
     * skipped rather than reported as an error, so the spare blank row at the
     * end of the form costs nothing.
     *
     * @return array{0: ServiceField[], 1: string[]}
     */
    private static function newCustomFields(mixed $value): array
    {
        if (!is_array($value)) {
            return [[], []];
        }

        $fields = [];
        $errors = [];
        foreach ($value as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $name = trim((string) ($spec['name'] ?? ''));
            $label = self::text($spec['label'] ?? null, 120);
            if ($name === '' && $label === '') {
                continue;
            }
            if (!ServiceField::isValidName($name)) {
                $errors[] = "নতুন ফিল্ডের নাম '{$name}' সঠিক নয় — ছোট ইংরেজি অক্ষর দিয়ে শুরু, তারপর ছোট অক্ষর/সংখ্যা/আন্ডারস্কোর (যেমন: 'mobile_number')। 'do', 'id', 'csrf' নামগুলো সংরক্ষিত।";
                continue;
            }
            if ($label === '') {
                $errors[] = "'{$name}' ফিল্ডের লেবেল দিন।";
                continue;
            }
            $fields[] = new ServiceField(
                $name,
                $label,
                ServiceField::normaliseType($spec['type'] ?? null),
                self::flag($spec['required'] ?? null),
                self::text($spec['placeholder'] ?? null, 120),
                self::text($spec['help'] ?? null, 190),
            );
        }

        return [$fields, $errors];
    }

    /**
     * A keyed sub-form such as `custom_label[<name>]`, read as a plain map.
     *
     * @return array<string, mixed>
     */
    private static function namedList(array $input, string $key): array
    {
        $value = $input[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $name => $item) {
            $map[(string) $name] = $item;
        }

        return $map;
    }

    /**
     * A trimmed, length-capped plain string. Non-scalars (a nested array posted
     * under a text input's name) collapse to an empty string rather than
     * throwing or producing "Array".
     */
    private static function text(mixed $value, int $max): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return mb_substr(trim((string) $value), 0, $max);
    }

    /**
     * Reads a checkbox value, treating the usual off-spellings as "off".
     */
    private static function flag(mixed $value): bool
    {
        if (!is_scalar($value)) {
            return false;
        }

        return !in_array(strtolower(trim((string) $value)), ['', '0', 'off', 'false', 'no'], true);
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
