<?php

declare(strict_types=1);

namespace App\Service;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\ServiceRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\ServiceProvider\MockNidService;
use App\ServiceProvider\MockTinService;
use App\ServiceProvider\MockVoterService;
use App\ServiceProvider\ServiceField;
use App\ServiceProvider\ServiceProviderInterface;
use App\ServiceProvider\ServiceResult;

/**
 * Resolves mock providers per service and executes them with balance + logging.
 */
final class ServiceManager
{
    /** @var array<string, ServiceProviderInterface> */
    private array $providers;

    public function __construct(
        private readonly ServiceRepository $services,
        private readonly TransactionRepository $transactions,
        private readonly UserRepository $users,
        private readonly ActivityLogRepository $logs,
        private readonly NotificationRepository $notifications,
    ) {
        $mocks = [new MockNidService(), new MockVoterService(), new MockTinService()];
        $this->providers = [];
        foreach ($mocks as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    /**
     * @return array<string, ServiceProviderInterface>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    public function providerFor(array $service): ?ServiceProviderInterface
    {
        $type = (string) ($service['service_type'] ?? 'mock');
        if ($type !== 'mock') {
            return null;
        }
        $slug = (string) $service['slug'];
        if (str_contains($slug, 'nid')) {
            return $this->providers['mock-nid'];
        }
        if (str_contains($slug, 'voter')) {
            return $this->providers['mock-voter'];
        }
        if (str_contains($slug, 'tin')) {
            return $this->providers['mock-tin'];
        }
        return $this->providers['mock-nid'];
    }

    /**
     * Effective form fields for a service: the provider defaults, narrowed and
     * re-flagged by the admin-configured `form_fields` list when one is stored.
     *
     * @return ServiceField[]
     */
    public function fieldsFor(array $service): array
    {
        $defaults = $this->defaultFieldsFor($service);
        $config = self::decodeFormFields($service);
        if ($config === null) {
            return $defaults;
        }

        $byName = [];
        foreach ($defaults as $field) {
            $byName[$field->name] = $field;
        }

        $fields = [];
        foreach ($config as $item) {
            $name = (string) ($item['name'] ?? '');
            $definition = $byName[$name] ?? null;
            if ($definition === null) {
                continue;
            }
            $fields[] = new ServiceField(
                $definition->name,
                $definition->label,
                $definition->type,
                (bool) ($item['required'] ?? $definition->required),
                $definition->placeholder,
                $definition->help,
            );
        }

        return $fields;
    }

    /**
     * Form field configuration as stored, normalised against the known fields.
     * Returns null when nothing is stored, i.e. provider defaults apply.
     *
     * @return array<int, array{name: string, required: bool}>|null
     */
    public function formFieldConfig(array $service): ?array
    {
        $config = self::decodeFormFields($service);
        if ($config === null) {
            return null;
        }

        $known = [];
        foreach ($this->defaultFieldsFor($service) as $field) {
            $known[$field->name] = $field;
        }

        $normalized = [];
        foreach ($config as $item) {
            $name = (string) ($item['name'] ?? '');
            if (!isset($known[$name])) {
                continue;
            }
            $normalized[] = [
                'name' => $name,
                'required' => (bool) ($item['required'] ?? $known[$name]->required),
            ];
        }

        return $normalized;
    }

    /**
     * Fields a service supports, regardless of the current configuration.
     *
     * @return ServiceField[]
     */
    public function defaultFieldsFor(array $service): array
    {
        return $this->providerFor($service)?->fields() ?? [];
    }

    /**
     * Drops every key the service form does not show (csrf, do, hand-crafted keys, …).
     *
     * @param ServiceField[] $fields
     */
    private static function pickConfiguredInput(array $fields, array $input): array
    {
        $picked = [];
        foreach ($fields as $field) {
            if (array_key_exists($field->name, $input)) {
                $picked[$field->name] = is_scalar($input[$field->name]) ? (string) $input[$field->name] : '';
            }
        }
        return $picked;
    }

    /**
     * @return array<int, array{name: string, required: bool}>|null
     */
    private static function decodeFormFields(array $service): ?array
    {
        $raw = $service['form_fields'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        return is_array($raw) ? $raw : null;
    }

    public function execute(array $service, Identity $user, array $input, string $ip, string $userAgent): ServiceResult
    {
        $provider = $this->providerFor($service);
        if ($provider === null) {
            return ServiceResult::fail('This service is not available for automated execution.');
        }

        $price = (float) $service['price'];
        $fresh = $this->users->findById($user->id);
        if ($fresh === null || (float) $fresh['balance'] < $price) {
            return ServiceResult::fail('পর্যাপ্ত ব্যালেন্স নেই। প্রয়োজনীয়: ৳' . number_format($price, 2));
        }

        // Only the configured fields are accepted, and the required ones must be filled in.
        $fields = $this->fieldsFor($service);
        $input = self::pickConfiguredInput($fields, $input);

        $missing = [];
        foreach ($fields as $field) {
            if ($field->required && trim((string) ($input[$field->name] ?? '')) === '') {
                $missing[$field->name] = $field->label . ' আবশ্যক।';
            }
        }
        if ($missing !== []) {
            return ServiceResult::fail('Validation failed.', $missing);
        }

        // Validate + execute through provider
        $result = $provider->execute($input);
        if (!$result->success) {
            return $result;
        }

        // Deduct balance, create transaction, notify + log
        $newBalance = round((float) $fresh['balance'] - $price, 2);
        $this->users->update($user->id, ['balance' => $newBalance]);

        $reference = 'TH' . strtoupper(dechex(time())) . random_int(1000, 9999);
        $txId = $this->transactions->create([
            'user_id' => $user->id,
            'service_id' => (int) $service['id'],
            'reference' => $reference,
            'amount' => $price,
            'status' => 'completed',
            'metadata' => ['provider' => $provider->key(), 'input_keys' => array_keys($input)],
        ]);

        $this->notifications->create(
            $user->id,
            'সার্ভিস সম্পন্ন হয়েছে',
            ($service['name'] ?? 'Service') . ' — রেফারেন্স: ' . $reference,
            'success'
        );
        $this->logs->create([
            'user_id' => $user->id,
            'action' => 'service.execute',
            'description' => ($service['name'] ?? 'Service') . ' executed',
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'metadata' => ['service_id' => $service['id'], 'tx' => $reference],
        ]);

        return new ServiceResult(true, $result->message, $result->data + ['_reference' => $reference, '_tx_id' => $txId], []);
    }
}
