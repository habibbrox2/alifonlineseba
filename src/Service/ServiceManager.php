<?php

declare(strict_types=1);

namespace App\Service;

use App\Auth\Identity;
use App\Notification\NotificationEvent;
use App\Notification\NotificationManager;
use App\Repository\ActivityLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\ServiceOrderRepository;
use App\Repository\ServiceRepository;
use App\Repository\ServiceSubmissionRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\ServiceProvider\MockNidMakeService;
use App\ServiceProvider\MockNidService;
use App\ServiceProvider\MockTinService;
use App\ServiceProvider\MockVoterService;
use App\ServiceProvider\ServiceField;
use App\ServiceProvider\ServiceProviderInterface;
use App\ServiceProvider\ServiceResult;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Resolves mock providers per service and executes them with balance + logging.
 */
final class ServiceManager
{
    /**
     * The operator's service-order queue — `/admin/orders`.
     *
     * Not `/admin/services`: that route is the service *catalogue* (create,
     * edit, price), and an order waiting for a decision is not in it. The
     * approve / reject / cancel controls the notification is meant to reach all
     * live on the order queue and its per-order desk.
     */
    public const ADMIN_QUEUE = '/admin/orders';

    /**
     * Seconds an auto-generating order shows as `processing` before it is
     * built.
     *
     * Not decoration. It is the honest report: rendering a card embeds two
     * images and runs a typesetter, and doing it inside the submit request
     * would hold the customer's browser open for the whole of it and give them
     * no way to leave the page while it worked. Settling on the next read
     * instead keeps the request that took the money fast, and gives the poll a
     * genuine thing to report. Anything under about two seconds reads as a
     * glitch; this is long enough to look like work and short enough that
     * nobody waits.
     */
    public const AUTO_SETTLE_DELAY_SECONDS = 3;

    /** @var array<string, ServiceProviderInterface> */
    private array $providers;

    public function __construct(
        private readonly ServiceRepository $services,
        private readonly ServiceOrderRepository $orders,
        private readonly LedgerService $ledger,
        private readonly UserRepository $users,
        private readonly ActivityLogRepository $logs,
        private readonly NotificationRepository $notifications,
        private readonly NotificationManager $notify,
        private readonly OrderWindowService $window,
        /**
         * Optional only so the six tests that build this class by hand keep
         * working without a storage root. Production always autowires it, and a
         * service with an image field and no storage is refused in
         * `storeUploadedImages()` rather than quietly dropping the file.
         *
         * Kept ninth deliberately: every existing construction site passes the
         * storage in this position, and appending after it is what lets them
         * keep working at all.
         */
        private readonly ?ImageUploadStorage $images = null,
        /**
         * Where the form answers are kept in a queryable shape.
         *
         * Optional for the same reason: production autowires it, and an order
         * placed without it still succeeds — the answers are in
         * `metadata.input` either way, so this table is a readable copy rather
         * than the only copy.
         */
        private readonly ?ServiceSubmissionRepository $submissions = null,
        /**
         * Turns an auto-generating order into the file it promised.
         *
         * Optional for the same reason as the rest, and because a
         * ServiceManager built by a test that never settles an order has no
         * business needing a PDF renderer. An order whose provider says it
         * auto-generates but has no renderer is completed with an error rather
         * than being left in `processing` for ever.
         */
        private readonly ?NidMakePdfRenderer $cards = null,
        private readonly ?DeliverableStorage $deliverables = null,
    ) {
        $mocks = [
            new MockNidService(),
            new MockNidMakeService(),
            new MockVoterService(),
            new MockTinService(),
        ];
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
        // `nid-make` also contains `nid`, so the more specific slug is matched
        // first — otherwise this service would resolve to the plain NID lookup.
        if (str_contains($slug, 'nid-make')) {
            return $this->providers['mock-nid-make'];
        }
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
     * A configured entry that matches no provider default is kept as a custom
     * field, provided it carries its own label — that label is what makes an
     * entry a definition rather than a stale name, and it is why an entry like
     * `{"name":"nope"}` is still discarded.
     *
     * Presence in the list means "shown" for a provider field, but a custom
     * field carries an explicit `enabled` flag: its definition has to survive
     * being switched off, otherwise unticking it would silently destroy it and
     * the admin could never bring it back.
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
            $name = is_array($item) ? (string) ($item['name'] ?? '') : '';
            $definition = $byName[$name] ?? null;
            if ($definition === null) {
                $custom = ServiceField::fromConfig($item);
                if ($custom !== null && self::isEntryEnabled($item)) {
                    $fields[] = $custom;
                }
                continue;
            }
            $fields[] = new ServiceField(
                $definition->name,
                $definition->label,
                $definition->type,
                (bool) ($item['required'] ?? $definition->required),
                $definition->placeholder,
                $definition->help,
                // The stored entry only carries `{name, required}`, so
                // everything else has to come from the provider definition.
                // Dropping it here would silently un-bundle every image budget
                // on any service an admin has ever configured a field on.
                $definition->maxBytes,
            );
        }

        return $fields;
    }

    /**
     * Form field configuration as stored, normalised against the known fields.
     * Returns null when nothing is stored, i.e. provider defaults apply.
     *
     * Provider fields come back as `{name, required}`; custom fields carry their
     * full definition so the admin UI can show them for editing.
     *
     * @return array<int, array<string, mixed>>|null
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
            $name = is_array($item) ? (string) ($item['name'] ?? '') : '';
            if (isset($known[$name])) {
                $normalized[] = [
                    'name' => $name,
                    'required' => (bool) ($item['required'] ?? $known[$name]->required),
                ];
                continue;
            }
            $custom = ServiceField::fromConfig($item);
            if ($custom !== null) {
                $normalized[] = $custom->toArray() + ['enabled' => self::isEntryEnabled($item)];
            }
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
     * Admin-defined fields currently stored for a service, in stored order.
     *
     * @return ServiceField[]
     */
    public function customFieldsFor(array $service): array
    {
        $customs = [];
        foreach ($this->formFieldConfig($service) ?? [] as $item) {
            $field = ServiceField::fromConfig($item);
            if ($field !== null) {
                $customs[] = $field;
            }
        }

        return $customs;
    }

    /**
     * An entry without an explicit flag is shown, so configs written before
     * custom fields existed keep working.
     */
    private static function isEntryEnabled(mixed $item): bool
    {
        return !is_array($item) || !array_key_exists('enabled', $item) || (bool) $item['enabled'];
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
    /**
     * The purchasable variants of a service, sanitised: label (non-empty) and
     * price (>= 0) only. Empty list = the service has no variants and the base
     * price applies — the UI hides the selector in that case.
     *
     * @return array<int, array{label: string, price: float}>
     */
    public static function variantsFor(array $service): array
    {
        $raw = $service['variants'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = is_string($raw)
            ? json_decode((string) $raw, true)
            : $raw;
        if (!is_array($decoded)) {
            return [];
        }

        $variants = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $price = (float) ($item['price'] ?? 0);
            if ($price < 0) {
                continue;
            }
            $variants[] = ['label' => $label, 'price' => $price];
        }

        return $variants;
    }

    /**
     * The variant matching a submitted "vN" id, or null when the service has
     * no variants or the id does not resolve (base price then applies).
     *
     * @return array{label: string, price: float}|null
     */
    public static function resolveVariant(array $service, string $variantId): ?array
    {
        if ($variantId === '' || !preg_match('/^v(\d+)$/', $variantId, $m)) {
            return null;
        }
        $variants = self::variantsFor($service);
        return $variants[(int) $m[1]] ?? null;
    }

    /**
     * Per-service ordering rules/instructions, split into lines for the UI.
     *
     * @return string[]
     */
    public static function rulesFor(array $service): array
    {
        $raw = trim((string) ($service['rules'] ?? ''));
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), explode("\n", $raw)),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * Form field configuration as stored, normalised against the known fields.
     * Returns null when nothing is stored, i.e. provider defaults apply.
     *
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
     * Persist the images attached to an order and hand back their stored paths.
     *
     * Three rules are the point of this method:
     *
     * 1. Only a configured field of type `image` is read. The posted file array
     *    is untrusted input, so an upload named for a field the form does not
     *    show — or for one that is a plain text box — is ignored rather than
     *    stored against whatever happened to be nearby.
     * 2. The stored *path* becomes the field's value, which is why an image field
     *    has to bypass the scalar check above: a file that is still in the
     *    browser is not an answer. It also means the path travels into the
     *    order metadata, so a retry or the PDF step can find the bytes again
     *    without the form being filled in twice.
     * 3. One bad upload does not leave the good ones behind. A failure returns
     *    the errors *and* deletes everything already written in this call, and
     *    the caller carries the successful paths so a later refusal can undo
     *    them too.
     *
     * @param ServiceField[] $fields
     * @param array<string, UploadedFileInterface> $files
     * @return array{errors: array<string, string>, values: array<string, string>, stored: string[]}
     */
    private function storeUploadedImages(array $fields, array $files, string $slug): array
    {
        $errors = [];
        $values = [];
        $stored = [];

        foreach ($fields as $field) {
            if ($field->type !== 'image') {
                continue;
            }

            $file = $files[$field->name] ?? null;
            if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
                if ($field->required) {
                    $errors[$field->name] = $field->label . ' আবশ্যক।';
                }
                continue;
            }

            if ($this->images === null) {
                $errors[$field->name] = 'ছবি আপলোড সাময়িকভাবে বন্ধ। একটু পরে আবার চেষ্টা করুন।';
                continue;
            }

            try {
                // The field's own budget, not a service-wide one: a photo and a
                // signature are answers to different questions and are not
                // worth the same number of bytes.
                $saved = $this->images->store($file, 'order-' . $slug, $field->maxBytes);
            } catch (RuntimeException $e) {
                $errors[$field->name] = $e->getMessage();
                continue;
            }

            $values[$field->name] = $saved['path'];
            $stored[] = $saved['path'];
        }

        if ($errors !== []) {
            $this->discardImages($stored);
            $stored = [];
            $values = [];
        }

        return ['errors' => $errors, 'values' => $values, 'stored' => $stored];
    }

    /**
     * Delete images written during this submit.
     *
     * Best effort by design: this only runs on a path that has already failed,
     * so a storage that cannot be reached must not replace one error with
     * another, and the files are hashed names outside the web root either way.
     *
     * @param string[] $paths
     */
    private function discardImages(array $paths): void
    {
        if ($this->images === null) {
            return;
        }

        foreach ($paths as $path) {
            $this->images->delete($path);
        }
    }

    /**
     * Store the form answers for an order as field-keyed JSON.
     *
     * `$input` here has already been through `pickConfiguredInput()` and merged
     * with the stored image paths, so it is exactly the set of answers this
     * order was accepted with — including the hashed storage paths, which are
     * what makes the NID card PDF able to find the photo again.
     *
     * Best effort by design. The copy in `service_order.metadata` is already
     * written and is what a retry reads, so a failure here must not replace one
     * completed order with an error — it only means the queryable copy is
     * missing, and every reader of it falls back.
     *
     * @param array<string, mixed> $values
     */
    private function recordSubmission(
        int $orderId,
        Identity $user,
        array $service,
        string $provider,
        array $values,
    ): void {
        if ($this->submissions === null) {
            return;
        }

        try {
            $this->submissions->save(
                $orderId,
                $user->id,
                isset($service['id']) ? (int) $service['id'] : null,
                $provider,
                $values,
            );
        } catch (\Throwable) {
            // Deliberately swallowed — see the note above. Logging it would be
            // reasonable, but there is no logger in this class and inventing one
            // for a best-effort copy is a bigger change than the failure is.
        }
    }

    /**
     * Charge the user and open an order in `pending`.
     *
     * The order is deliberately *not* executed here: the history page owns the
     * lifecycle, so a user can cancel a pending order and get the money back.
     *
     * @param array<string, UploadedFileInterface> $files Uploaded files keyed by
     *        field name, from the PSR-7 request. Only fields of type `image` are
     *        read; everything else in the array is ignored.
     */
    public function submit(
        array $service,
        Identity $user,
        array $input,
        string $ip,
        string $userAgent,
        array $files = [],
    ): ServiceResult {
        // The operator's daily intake window (default 08:00–22:00 Bangladesh
        // time) gates *placing* an order and nothing else. start()/retry() stay
        // reachable overnight on purpose: those requests are already paid for,
        // and refusing to run one would hold the user's money until morning.
        if (!$this->window->isOpen()) {
            return ServiceResult::fail($this->window->closedMessage());
        }

        $provider = $this->providerFor($service);
        if ($provider === null) {
            return ServiceResult::fail('This service is not available for automated execution.');
        }

        // Variant pricing: a variant id ("v0", "v1", …) picks a configured
        // variant's price; a missing/unknown one falls back to the base price.
        $variant = self::resolveVariant($service, (string) ($input['variant'] ?? ''));
        $price = $variant !== null ? (float) $variant['price'] : (float) $service['price'];

        // The one-time freebie: a user with free searches left runs this request
        // without paying. consumeFreeSearch() is atomic, so two simultaneous
        // submits can never both ride the same free search.
        $usedFreeSearch = $this->users->freeSearches($user->id) > 0 && $this->users->consumeFreeSearch($user->id);
        if (!$usedFreeSearch && !$this->hasBalance($user->id, $price)) {
            return ServiceResult::fail('পর্যাপ্ত ব্যালেন্স নেই। প্রয়োজনীয়: ৳' . number_format($price, 2));
        }
        if ($usedFreeSearch) {
            $price = 0.0;
        }

        // Only the configured fields are accepted, and the required ones must be filled in.
        $fields = $this->fieldsFor($service);
        $input = self::pickConfiguredInput($fields, $input);

        $missing = [];
        foreach ($fields as $field) {
            // An image field is answered by a file, not by a posted value, so
            // there is nothing in `$input` to check yet — `storeUploadedImages()`
            // is what decides, and it reports the same "label আবশ্যক।" shape.
            if ($field->type === 'image') {
                continue;
            }
            if ($field->required && trim((string) ($input[$field->name] ?? '')) === '') {
                $missing[$field->name] = $field->label . ' আবশ্যক।';
            }
        }
        if ($missing !== []) {
            return ServiceResult::fail('Validation failed.', $missing);
        }

        // One format for every date field, decided here rather than per
        // provider: the browser shows `06-10-2026`, the row stores
        // `2026-10-06`, and ServiceDate is the only thing allowed to convert
        // between them. A value it cannot read fails *before* any money moves,
        // with the same message on every service — which is what makes the
        // format on the form a promise rather than a hint.
        $dates = ServiceDate::normaliseFields($fields, $input);
        if ($dates['errors'] !== []) {
            return ServiceResult::fail('Validation failed.', $dates['errors']);
        }
        // array_replace, not `+`: the key already exists holding the raw
        // answer, and the union operator would keep that one.
        $input = array_replace($input, $dates['values']);

        // Images are written to disk here, before the order exists, so that a
        // refused upload costs the user a form error and nothing else. Every path
        // stored along the way is remembered and taken back down if the order
        // then fails to open, because the storage layout is hashed: an orphan
        // there would never be found, let alone collected.
        $images = $this->storeUploadedImages($fields, $files, (string) ($service['slug'] ?? 'service'));
        if ($images['errors'] !== []) {
            return ServiceResult::fail('Validation failed.', $images['errors']);
        }
        $input += $images['values'];

        // The charge is a ledger entry, not a bare column update: the order
        // sitting in the queue has to be explainable as "this much left this
        // account at this moment", and that is only true if there is a row
        // saying so. The order row is written first so the entry has something
        // to point at — a debit with no order reference is money that vanished.
        $reference = self::newReference();

        // An auto-generating order is born `processing`: there is no operator
        // step for it to wait in, and `pending` is exactly the queue an admin
        // works through — putting one there would be the review process this
        // service is meant not to have.
        $autoGenerate = $provider->autoGenerate();

        $orderId = $this->orders->create([
            'user_id' => $user->id,
            'service_id' => (int) $service['id'],
            'reference' => $reference,
            'amount' => $price,
            'status' => $autoGenerate ? StatusPresenter::PROCESSING : StatusPresenter::PENDING,
            'metadata' => [
                'provider' => $provider->key(),
                'input_keys' => array_keys($input),
                // Kept so a retry can re-run the provider without the form again.
                'input' => $input,
                'service_name' => (string) ($service['name'] ?? 'Service'),
                'variant' => $variant['label'] ?? null,
                'free_search' => $usedFreeSearch,
                // The two facts `settleAutoOrders()` needs and cannot infer:
                // that this provider builds its own output, and when the clock
                // started for it. Both are written once, here, and never edited.
                'auto_generate' => $autoGenerate,
                'auto_started_at' => $autoGenerate ? time() : null,
            ],
        ]);

        if ($price > 0) {
            [$charged] = $this->ledger->debitUser($user->id, $price, [
                'type' => TransactionRepository::TYPE_ORDER_DEBIT,
                'service_order_id' => $orderId,
                'description' => sprintf('সার্ভিস অর্ডার %s', $reference),
                'metadata' => ['service_name' => (string) ($service['name'] ?? '')],
            ]);

            if (!$charged) {
                // The order row exists but nothing was taken for it. Remove it
                // rather than leaving a queue entry nobody is going to fulfil
                // and cannot be cancelled for a refund (there is no money).
                $this->orders->update($orderId, ['status' => StatusPresenter::CANCELLED]);
                $this->discardImages($images['stored']);
                return ServiceResult::fail('অর্ডারটি গ্রহণ করা যায়নি — ব্যালেন্স পরিবর্তন হয়নি।');
            }
        }

        // Written once the order is real: it has an id to point at, and it is
        // past the point where a failed charge would cancel the row — and
        // cancelling cascades this submission away with it, so writing it
        // earlier would mean writing a row that is about to be deleted.
        $this->recordSubmission($orderId, $user, $service, $provider->key(), $input);

        // Two audiences, two landing pages. The customer wants the row that
        // was just created; staff want the queue entry that needs a decision,
        // pre-filtered to this reference so the order is on screen rather than
        // somewhere in page two. Without the admin link every staff copy —
        // in-app, push and Telegram alike — pointed at the customer's own
        // history page, which staff do not have.
        $this->notify->dispatch(
            NotificationEvent::SERVICE_REQUEST_CREATED,
            $user->id,
            ['service' => (string) ($service['name'] ?? 'Service'), 'reference' => $reference, 'user_id' => $user->id],
            '/service-history',
            ['reference' => $reference, 'tx_id' => $orderId],
            self::ADMIN_QUEUE . '?q=' . rawurlencode($reference),
        );
        $this->log($user, 'service.submit', ($service['name'] ?? 'Service') . ' requested', $ip, $userAgent, $service, $reference);

        return new ServiceResult(
            true,
            $autoGenerate
                ? 'আপনার এনআইডি কার্ড তৈরি হচ্ছে। একটু পরেই সার্ভিস হিস্ট্রি থেকে ডাউনলোড করতে পারবেন।'
                : 'অনুরোধটি গ্রহণ করা হয়েছে। এখন সার্ভিস হিস্ট্রি থেকে চালু করতে পারবেন।',
            [
                '_reference' => $reference,
                '_request_id' => $orderId,
                '_status' => $autoGenerate ? StatusPresenter::PROCESSING : StatusPresenter::PENDING,
            ],
            [],
        );
    }

    /**
     * Build the output for any auto-generating order whose delay has elapsed.
     *
     * Called from the two places that read a customer's orders — the history
     * page and the poller that watches it — so the file appears on the same
     * tick the user would have refreshed on anyway. Nothing else has to run:
     * no cron, no worker, no queue.
     *
     * A GET that writes is unusual enough to be worth defending. Three things
     * keep it honest:
     *
     *  - the work is idempotent, because the status guard below is a single
     *    conditional UPDATE. Two polls racing produce one card; the loser
     *    matches zero rows and deletes the file it had already written.
     *  - it only ever touches rows whose own metadata says they are
     *    auto-generating, so it cannot touch an order an operator is working.
     *  - and a customer who never opens the page again still has a `pending`
     *    order rather than a half-built one, because nothing is written until
     *    the guard passes.
     *
     * @param array<int, array<string, mixed>> $orders raw `service_order` rows
     */
    public function settleAutoOrders(array $orders): void
    {
        if ($this->cards === null || $this->deliverables === null) {
            return;
        }

        foreach ($orders as $row) {
            $this->settleOne($row);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function settleOne(array $row): void
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || (string) ($row['status'] ?? '') !== StatusPresenter::PROCESSING) {
            return;
        }

        $metadata = ServiceOrderRepository::metadata($row);
        if (($metadata['auto_generate'] ?? false) !== true) {
            return;
        }

        $startedAt = (int) ($metadata['auto_started_at'] ?? 0);
        if ($startedAt > 0 && (time() - $startedAt) < self::AUTO_SETTLE_DELAY_SECONDS) {
            return; // Still inside the window the customer is meant to see.
        }

        $input = is_array($metadata['input'] ?? null) ? $metadata['input'] : [];
        $provider = $this->providers[(string) ($metadata['provider'] ?? '')] ?? null;
        if ($provider === null || $input === []) {
            return;
        }

        $result = $provider->execute($input);
        if (!$result->success) {
            // Leave it `processing`: a card that will not render is worth a
            // second attempt on the next tick, not a terminal failure the
            // customer did not cause. The reason goes in the metadata anyway,
            // because an order that never finishes looks exactly like one that
            // is merely still going without it.
            $this->orders->setStatusIf(
                $id,
                StatusPresenter::PROCESSING,
                StatusPresenter::PROCESSING,
                ['auto_error' => $result->message ?: 'provider rejected the stored answers'],
            );
            return;
        }

        // Claim the row before building anything. `status = processing` in the
        // WHERE clause is what makes a second concurrent poll match zero rows,
        // and the loser then deletes the file it had already written rather
        // than leaving two cards for one order.
        $claimed = $this->orders->setStatusIf(
            $id,
            StatusPresenter::COMPLETED,
            StatusPresenter::PROCESSING,
            [
                'result' => $result->data,
                'settled_at' => date('Y-m-d H:i:s'),
            ],
        );

        try {
            $pdf = $this->cards->render($input, [
                'reference' => (string) ($row['reference'] ?? ''),
                'generated_at' => date('d/m/Y h:i A'),
            ]);
            $stored = $this->deliverables->store(self::syntheticUpload($pdf, $this->cardFilename($row)), $id);
        } catch (\Throwable $e) {
            if ($claimed) {
                // Put it back the way it was so the next tick retries, rather
                // than leaving a completed order with nothing to download. The
                // message is kept on the row for the same reason as above.
                $this->orders->setStatusIf(
                    $id,
                    StatusPresenter::PROCESSING,
                    StatusPresenter::COMPLETED,
                    ['auto_error' => $e->getMessage()],
                );
            }
            return;
        }

        if (!$claimed) {
            $this->deliverables->delete($stored['path']);
            return;
        }

        // Two writes, and deliberately no re-assertion of the status between
        // them: a cancellation landing in between must stay cancelled, and the
        // columns are harmless on a cancelled order — the file is never served
        // from one.
        $this->orders->attachDeliverable($id, $stored, (int) $row['user_id']);
    }

    /**
     * Wrap rendered bytes as an upload {@see DeliverableStorage::store()} accepts.
     *
     * It takes an `UploadedFileInterface` because it was built for an admin
     * dragging a file out of a folder, and reusing that validator — the
     * extension derived from sniffed bytes, the size ceiling, the directory
     * per order — is worth more than a second entry point that would have to
     * duplicate all of it. `moveTo()` falls back to a stream copy for a file
     * that never came through an HTTP POST, which is exactly this case.
     */
    private static function syntheticUpload(string $bytes, string $name): UploadedFileInterface
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return new UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, $name, 'application/pdf');
    }

    /** A download name built from the reference, so no user text reaches a header. */
    private function cardFilename(array $row): string
    {
        $reference = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($row['reference'] ?? ''));

        return 'nid-card-' . ($reference === '' ? 'order' : $reference) . '.pdf';
    }

    /**
     * Run a pending order through its provider and store the result.
     *
     * Note where it stops: at `processing`, not `completed`. Completion is the
     * admin's decision and it is the edge on which money changes hands — an
     * operator's earnings exist because they approved the order. Letting the
     * customer finish their own order would close the order with nobody paid
     * and nothing left for an admin to approve, so the button that used to
     * "finish" it now means "start it", which is also the honest label.
     *
     * On failure the charged amount is refunded, so a failed order is always
     * retryable without the user topping up again.
     */
    public function start(array $request, Identity $user, string $ip, string $userAgent): ServiceResult
    {
        // Re-read: a caller may hand us a row it read before someone else moved
        // the order on, and re-running a settled order would charge twice.
        $request = $this->fresh($request);
        $id = (int) $request['id'];
        if ((string) $request['status'] !== StatusPresenter::PENDING) {
            return ServiceResult::fail('এই অর্ডারটি ইতিমধ্যে প্রক্রিয়া করা হয়েছে।');
        }

        $metadata = ServiceOrderRepository::metadata($request);
        $provider = $this->providers[(string) ($metadata['provider'] ?? '')] ?? null;
        if ($provider === null) {
            $this->fail($request, $user, $ip, $userAgent, 'সার্ভিস প্রদানক পাওয়া যায়নি।');
            return ServiceResult::fail('সার্ভিস প্রদানক পাওয়া যায়নি।');
        }

        $this->orders->setStatus($id, StatusPresenter::PROCESSING);

        $result = $provider->execute((array) ($metadata['input'] ?? []));
        if (!$result->success) {
            $this->fail($request, $user, $ip, $userAgent, $result->message);
            return $result;
        }

        $this->orders->setStatus($id, StatusPresenter::PROCESSING, ['result' => $result->data]);

        $reference = (string) $request['reference'];
        $serviceName = (string) ($metadata['service_name'] ?? 'Service');
        $this->notify->dispatch(
            NotificationEvent::SERVICE_REQUEST_PROCESSING,
            $user->id,
            ['service' => $serviceName, 'reference' => $reference],
            '/service-history',
            ['reference' => $reference, 'tx_id' => $id],
        );
        $this->log($user, 'service.complete', $serviceName . ' started', $ip, $userAgent, null, $reference);

        return new ServiceResult(
            true,
            $result->message,
            $result->data + [
                '_reference' => $reference,
                '_request_id' => $id,
                '_status' => StatusPresenter::PROCESSING,
            ],
            [],
        );
    }

    /** Cancel a still-pending order and refund it. */
    public function cancel(array $request, Identity $user, string $ip, string $userAgent): ServiceResult
    {
        $request = $this->fresh($request);
        $id = (int) $request['id'];
        if ((string) $request['status'] !== StatusPresenter::PENDING) {
            return ServiceResult::fail('শুধুমাত্র পেন্ডিং অর্ডার বাতিল করা যায়।');
        }

        $amount = abs((float) $request['amount']);

        // Refund first. If it fails the order stays open and the customer can
        // try again; the opposite order would close the order with their money
        // still held and nothing left to retry.
        if ($amount > 0) {
            [$refunded, $message] = $this->ledger->creditUser((int) $request['user_id'], $amount, [
                'type' => TransactionRepository::TYPE_ORDER_REFUND,
                'service_order_id' => $id,
                'description' => sprintf('অর্ডার %s বাতিল — টাকা ফেরত', (string) $request['reference']),
            ]);
            if (!$refunded) {
                return ServiceResult::fail($message);
            }
        }

        $this->orders->setStatus($id, StatusPresenter::CANCELLED);

        $reference = (string) $request['reference'];
        $this->notify->dispatch(
            NotificationEvent::SERVICE_REQUEST_CANCELLED,
            $user->id,
            ['reference' => $reference, 'amount' => number_format($amount, 2)],
            '/service-history',
            ['reference' => $reference, 'tx_id' => $id],
        );
        $this->log($user, 'service.cancel', 'Request cancelled', $ip, $userAgent, null, $reference);

        return new ServiceResult(
            true,
            'অর্ডারটি বাতিল হয়েছে এবং টাকা ফেরত দেওয়া হয়েছে।',
            ['_reference' => $reference, '_request_id' => $id, '_status' => StatusPresenter::CANCELLED],
            [],
        );
    }

    /**
     * Re-charge a failed or cancelled order and run it again.
     *
     * Cancelling and failing both refund, so the retry pays the price again.
     */
    public function retry(array $request, Identity $user, string $ip, string $userAgent): ServiceResult
    {
        $request = $this->fresh($request);
        $id = (int) $request['id'];
        $status = (string) $request['status'];
        if (!in_array($status, [StatusPresenter::FAILED, StatusPresenter::CANCELLED], true)) {
            return ServiceResult::fail('শুধুমাত্র ব্যর্থ বা বাতিল অর্ডার পুনরায় চালানো যায়।');
        }

        $price = (float) $request['amount'];
        if ($price > 0 && !$this->hasBalance($user->id, $price)) {
            return ServiceResult::fail('পর্যাপ্ত ব্যালেন্স নেই। প্রয়োজনীয়: ৳' . number_format($price, 2));
        }

        $attempts = (int) (ServiceOrderRepository::metadata($request)['attempts'] ?? 0) + 1;

        // The order is reopened *after* the charge lands, for the same reason
        // everywhere else in this class: a failed debit must leave the order
        // closed, not open and unpaid.
        if ($price > 0) {
            [$charged, $message] = $this->ledger->debitUser($user->id, $price, [
                'type' => TransactionRepository::TYPE_ORDER_DEBIT,
                'service_order_id' => $id,
                'description' => sprintf('অর্ডার %s পুনরায় চালানো', (string) $request['reference']),
                'metadata' => ['attempt' => $attempts],
            ]);
            if (!$charged) {
                return ServiceResult::fail($message);
            }
        }

        $this->orders->setStatus($id, StatusPresenter::PENDING, ['attempts' => $attempts]);
        $this->log($user, 'service.retry', 'Request retried (attempt ' . $attempts . ')', $ip, $userAgent, null, (string) $request['reference']);

        // start() re-reads the row, so it now sees the order in its pending state.
        return $this->start($request, $user, $ip, $userAgent);
    }

    /** The current database state of an order, falling back to what we were given. */
    private function fresh(array $request): array
    {
        return $this->orders->findById((int) $request['id']) ?? $request;
    }

    private function hasBalance(int $userId, float $price): bool
    {
        $fresh = $this->users->findById($userId);
        return $fresh !== null && (float) $fresh['balance'] >= $price;
    }

    private function fail(array $request, Identity $user, string $ip, string $userAgent, string $reason): void
    {
        $id = (int) $request['id'];
        $this->orders->setStatus($id, StatusPresenter::FAILED, ['error' => $reason]);

        $amount = abs((float) $request['amount']);
        if ($amount > 0) {
            $this->ledger->creditUser((int) $request['user_id'], $amount, [
                'type' => TransactionRepository::TYPE_ORDER_REFUND,
                'service_order_id' => $id,
                'description' => sprintf('অর্ডার %s ব্যর্থ — টাকা ফেরত', (string) $request['reference']),
            ]);
        }

        $reference = (string) $request['reference'];
        $this->notify->dispatch(
            NotificationEvent::SERVICE_REQUEST_FAILED,
            $user->id,
            ['reference' => $reference, 'reason' => $reason],
            '/service-history',
            ['reference' => $reference, 'tx_id' => $id],
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
        return 'AL' . strtoupper(dechex(time())) . random_int(1000, 9999);
    }
}
