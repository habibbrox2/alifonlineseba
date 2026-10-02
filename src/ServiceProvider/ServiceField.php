<?php

declare(strict_types=1);

namespace App\ServiceProvider;

/**
 * A single field of a service form.
 */
final class ServiceField
{
    /**
     * Input types a field may use. Deliberately a whitelist: the type ends up
     * verbatim in an HTML `type="…"` attribute on the public form, so an
     * arbitrary string from the database must never reach it.
     */
    public const TYPES = ['text', 'textarea', 'number', 'date', 'email', 'tel'];

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

    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $required = true,
        public readonly string $placeholder = '',
        public readonly string $help = '',
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
        );
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
        ];
    }
}
