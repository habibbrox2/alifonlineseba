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

    /**
     * Charge the user and open a request in `pending`.
     *
     * The request is deliberately *not* executed here: the history page owns the
     * lifecycle, so a user can cancel a pending request and get the money back.
     */
    public function submit(array $service, Identity $user, array $input, string $ip, string $userAgent): ServiceResult
    {
        $provider = $this->providerFor($service);
        if ($provider === null) {
            return ServiceResult::fail('This service is not available for automated execution.');
        }

        $price = (float) $service['price'];
        if (!$this->hasBalance($user->id, $price)) {
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

        $this->users->adjustBalance($user->id, -$price);

        $reference = self::newReference();
        $txId = $this->transactions->create([
            'user_id' => $user->id,
            'service_id' => (int) $service['id'],
            'reference' => $reference,
            'amount' => $price,
            'status' => StatusPresenter::PENDING,
            'metadata' => [
                'provider' => $provider->key(),
                'input_keys' => array_keys($input),
                // Kept so a retry can re-run the provider without the form again.
                'input' => $input,
                'service_name' => (string) ($service['name'] ?? 'Service'),
            ],
        ]);

        $this->notifications->create(
            $user->id,
            'অনুরোধ গৃহীত হয়েছে',
            ($service['name'] ?? 'Service') . ' — রেফারেন্স: ' . $reference,
            'info'
        );
        $this->log($user, 'service.submit', ($service['name'] ?? 'Service') . ' requested', $ip, $userAgent, $service, $reference);

        return new ServiceResult(
            true,
            'অনুরোধটি গ্রহণ করা হয়েছে। এখন সার্ভিস হিস্ট্রি থেকে চালু করতে পারবেন।',
            ['_reference' => $reference, '_request_id' => $txId, '_status' => StatusPresenter::PENDING],
            [],
        );
    }

    /**
     * Run a pending request through its provider and settle the final status.
     *
     * On failure the charged amount is refunded, so a failed request is always
     * retryable without the user topping up again.
     */
    public function start(array $request, Identity $user, string $ip, string $userAgent): ServiceResult
    {
        // Re-read: a caller may hand us a row it read before someone else moved
        // the request on, and re-running a settled request would charge twice.
        $request = $this->fresh($request);
        $id = (int) $request['id'];
        if ((string) $request['status'] !== StatusPresenter::PENDING) {
            return ServiceResult::fail('এই অনুরোধটি ইতিমধ্যে প্রক্রিয়া করা হয়েছে।');
        }

        $metadata = TransactionRepository::metadata($request);
        $provider = $this->providers[(string) ($metadata['provider'] ?? '')] ?? null;
        if ($provider === null) {
            $this->fail($request, $user, $ip, $userAgent, 'সার্ভিস প্রদানক পাওয়া যায়নি।');
            return ServiceResult::fail('সার্ভিস প্রদানক পাওয়া যায়নি।');
        }

        $this->transactions->setStatus($id, StatusPresenter::PROCESSING);

        $result = $provider->execute((array) ($metadata['input'] ?? []));
        if (!$result->success) {
            $this->fail($request, $user, $ip, $userAgent, $result->message);
            return $result;
        }

        $this->transactions->setStatus($id, StatusPresenter::COMPLETED, ['result' => $result->data]);

        $reference = (string) $request['reference'];
        $serviceName = (string) ($metadata['service_name'] ?? 'Service');
        $this->notifications->create(
            $user->id,
            'সার্ভিস সম্পন্ন হয়েছে',
            $serviceName . ' — রেফারেন্স: ' . $reference,
            'success'
        );
        $this->log($user, 'service.complete', $serviceName . ' completed', $ip, $userAgent, null, $reference);

        return new ServiceResult(
            true,
            $result->message,
            $result->data + [
                '_reference' => $reference,
                '_request_id' => $id,
                '_status' => StatusPresenter::COMPLETED,
            ],
            [],
        );
    }

    /** Cancel a still-pending request and refund it. */
    public function cancel(array $request, Identity $user, string $ip, string $userAgent): ServiceResult
    {
        $request = $this->fresh($request);
        $id = (int) $request['id'];
        if ((string) $request['status'] !== StatusPresenter::PENDING) {
            return ServiceResult::fail('শুধুমাত্র পেন্ডিং অনুরোধ বাতিল করা যায়।');
        }

        $this->transactions->setStatus($id, StatusPresenter::CANCELLED);
        $this->refund($request);

        $reference = (string) $request['reference'];
        $this->notifications->create(
            $user->id,
            'অনুরোধ বাতিল হয়েছে',
            'রেফারেন্স: ' . $reference . ' — ৳' . number_format((float) $request['amount'], 2) . ' ফেরত দেওয়া হয়েছে।',
            'warning'
        );
        $this->log($user, 'service.cancel', 'Request cancelled', $ip, $userAgent, null, $reference);

        return new ServiceResult(
            true,
            'অনুরোধটি বাতিল হয়েছে এবং টাকা ফেরত দেওয়া হয়েছে।',
            ['_reference' => $reference, '_request_id' => $id, '_status' => StatusPresenter::CANCELLED],
            [],
        );
    }

    /**
     * Re-charge a failed or cancelled request and run it again.
     *
     * Cancelling and failing both refund, so the retry pays the price again.
     */
    public function retry(array $request, Identity $user, string $ip, string $userAgent): ServiceResult
    {
        $request = $this->fresh($request);
        $id = (int) $request['id'];
        $status = (string) $request['status'];
        if (!in_array($status, [StatusPresenter::FAILED, StatusPresenter::CANCELLED], true)) {
            return ServiceResult::fail('শুধুমাত্র ব্যর্থ বা বাতিল অনুরোধ পুনরায় চালানো যায়।');
        }

        $price = (float) $request['amount'];
        if (!$this->hasBalance($user->id, $price)) {
            return ServiceResult::fail('পর্যাপ্ত ব্যালেন্স নেই। প্রয়োজনীয়: ৳' . number_format($price, 2));
        }

        $attempts = (int) (TransactionRepository::metadata($request)['attempts'] ?? 0) + 1;
        $this->users->adjustBalance($user->id, -$price);
        $this->transactions->setStatus($id, StatusPresenter::PENDING, ['attempts' => $attempts]);
        $this->log($user, 'service.retry', 'Request retried (attempt ' . $attempts . ')', $ip, $userAgent, null, (string) $request['reference']);

        // start() re-reads the row, so it now sees the request in its pending state.
        return $this->start($request, $user, $ip, $userAgent);
    }

    /** The current database state of a request, falling back to what we were given. */
    private function fresh(array $request): array
    {
        return $this->transactions->findById((int) $request['id']) ?? $request;
    }

    private function hasBalance(int $userId, float $price): bool
    {
        $fresh = $this->users->findById($userId);
        return $fresh !== null && (float) $fresh['balance'] >= $price;
    }

    private function refund(array $request): void
    {
        $this->users->adjustBalance((int) $request['user_id'], abs((float) $request['amount']));
    }

    private function fail(array $request, Identity $user, string $ip, string $userAgent, string $reason): void
    {
        $id = (int) $request['id'];
        $this->transactions->setStatus($id, StatusPresenter::FAILED, ['error' => $reason]);
        $this->refund($request);

        $reference = (string) $request['reference'];
        $this->notifications->create(
            $user->id,
            'সার্ভিস ব্যর্থ হয়েছে',
            'রেফারেন্স: ' . $reference . ' — ' . $reason,
            'danger'
        );
        $this->log($user, 'service.failed', $reason, $ip, $userAgent, null, $reference);
    }

    private function log(
        Identity $user,
        string $action,
        string $description,
        string $ip,
        string $userAgent,
        ?array $service,
        string $reference,
    ): void {
        $this->logs->create([
            'user_id' => $user->id,
            'action' => $action,
            'description' => $description,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'metadata' => array_filter([
                'service_id' => $service['id'] ?? null,
                'tx' => $reference,
            ], static fn ($value): bool => $value !== null),
        ]);
    }

    private static function newReference(): string
    {
        return 'TH' . strtoupper(dechex(time())) . random_int(1000, 9999);
    }
}
