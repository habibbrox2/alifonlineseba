<?php

declare(strict_types=1);

namespace App\ServiceProvider;

/**
 * A single field of a service form.
 */
final class ServiceField
{
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
            (string) ($data['type'] ?? 'text'),
            (bool) ($data['required'] ?? true),
            (string) ($data['placeholder'] ?? ''),
            (string) ($data['help'] ?? ''),
        );
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
