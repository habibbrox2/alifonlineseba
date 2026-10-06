<?php

declare(strict_types=1);

namespace App\ServiceProvider;

use App\Service\ImageUploadStorage;

/**
 * A single field of a service form.
 */
final class ServiceField
{
    /**
     * Input types a field may use. Deliberately a whitelist: the type ends up
     * verbatim in an HTML `type="…"` attribute on the public form, so an
     * arbitrary string from the database must never reach it.
     *
     * `image` is not an HTML input type — it is rendered as the drop zone in
     * `components/form-row.twig` and stored by `ImageUploadStorage`, which
     * refuses anything whose *sniffed* type is not an image. `file` stays on the
     * list because a stored configuration may still name it; it renders as a
     * plain file input.
     */
    public const TYPES = ['file','image', 'text', 'textarea', 'number', 'date', 'email', 'tel'];

    /**
     * Field names become HTML attribute names and POST keys, so they are
     * restricted to a conservative identifier.
     */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /**
     * Keys the request handlers read for themselves. A field named `do` or
     * `id` would render an input under that same name inside the admin form and
     * be mistaken for the action's own dispatch parameter, so these are refused
     * as field names even though they match the pattern.
     */
    public const RESERVED_NAMES = ['do', 'id', 'csrf'];

    /**
     * Bounds on a stored image's size, in bytes.
     *
     * Anything outside is clamped rather than rejected, because this value
     * reaches here from a stored JSON configuration an admin can edit by hand.
     * The floor is below any usable image; the ceiling is the upload ceiling
     * itself, so a field can never ask for more than
     * {@see ImageUploadStorage::MAX_BYTES} and then be surprised.
     */
    private const MIN_MAX_BYTES = 8 * 1024;

    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $required = true,
        public readonly string $placeholder = '',
        public readonly string $help = '',
        /**
         * Byte budget for an `image` field, or null to mean "no target, just
         * the shared ceiling".
         *
         * It lives on the field rather than on the service because a photo and
         * a signature answer different questions: the photo has to hold a face,
         * the signature only a pen stroke, and there is no reason for the
         * second to be allowed the first one's disk bill.
         */
        public readonly ?int $maxBytes = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            (string) ($data['label'] ?? ''),
            self::normaliseType($data['type'] ?? null),
            (bool) ($data['required'] ?? true),
            (string) ($data['placeholder'] ?? ''),
            (string) ($data['help'] ?? ''),
            self::normaliseMaxBytes($data['max_bytes'] ?? null),
        );
    }

    /**
     * A byte budget from a stored configuration, or null for "no target".
     *
     * A JSON configuration can hold anything at all, so this is defensive by
     * necessity rather than by taste: a string, a float, a negative number and
     * a list all have to mean either a sane target or no target at all, and
     * none of them may reach `store()`.
     */
    public static function normaliseMaxBytes(mixed $value): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit(trim($value)))) {
            return null;
        }

        $bytes = (int) $value;
        if ($bytes < self::MIN_MAX_BYTES) {
            return null;
        }

        return min($bytes, ImageUploadStorage::MAX_BYTES);
    }

    /**
     * Builds a field from a stored configuration entry, or null when the entry
     * is not a usable definition. An unusable name or a missing label means
     * "not a field", so the caller drops the entry.
     *
     * Text is capped so a bloated or hand-edited config cannot push an
     * unbounded label into every rendered form.
     */
    public static function fromConfig(mixed $item): ?self
    {
        if (!is_array($item)) {
            return null;
        }
        $name = trim((string) ($item['name'] ?? ''));
        $label = trim((string) ($item['label'] ?? ''));
        if (!self::isValidName($name) || $label === '') {
            return null;
        }

        return new self(
            $name,
            mb_substr($label, 0, 120),
            self::normaliseType($item['type'] ?? null),
            (bool) ($item['required'] ?? true),
            mb_substr(trim((string) ($item['placeholder'] ?? '')), 0, 120),
            mb_substr(trim((string) ($item['help'] ?? '')), 0, 190),
            self::normaliseMaxBytes($item['max_bytes'] ?? null),
        );
    }

    /**
     * A field name is usable only if it is a plain, non-reserved identifier;
     * this is what keeps a stored configuration from injecting markup or
     * colliding with the request's own keys.
     */
    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1
            && !in_array($name, self::RESERVED_NAMES, true);
    }

    /**
     * Unknown types fall back to a plain text input rather than being rejected,
     * so a config written by a future version still renders.
     */
    public static function normaliseType(mixed $type): string
    {
        $type = is_string($type) ? strtolower(trim($type)) : '';

        return in_array($type, self::TYPES, true) ? $type : 'text';
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'placeholder' => $this->placeholder,
            'help' => $this->help,
            'max_bytes' => $this->maxBytes,
        ];
    }
}
