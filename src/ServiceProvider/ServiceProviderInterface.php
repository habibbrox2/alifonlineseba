<?php

declare(strict_types=1);

namespace App\ServiceProvider;

/**
 * Contract for executing a service. Implementations return demo data only.
 */
interface ServiceProviderInterface
{
    /**
     * Unique key, e.g. "mock-nid".
     */
    public function key(): string;

    /**
     * Human-readable provider name.
     */
    public function name(): string;

    /**
     * Form field schema for the service.
     *
     * @return ServiceField[]
     */
    public function fields(): array;

    /**
     * Static requirement bullets shown on the service page.
     *
     * @return string[]
     */
    public function requirements(): array;

    /**
     * Execute the service with validated input. MUST return demo data only.
     */
    public function execute(array $input): ServiceResult;
}
