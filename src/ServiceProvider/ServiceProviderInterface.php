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

    /**
     * Whether an order for this service generates itself, with no operator.
     *
     * False for every service that a human has to look at: the order waits in
     * `pending`, an admin claims it, and money moves on an approval.
     *
     * True for a service that is a document the platform can assemble itself —
     * the customer fills in a form, and the only question is whether the
     * renderer produced a file. Such an order goes straight to `processing`,
     * is completed by {@see \App\Service\ServiceManager::settleAutoOrders()}
     * once its delay has elapsed, and never appears in an admin's queue at
     * all. Putting a human in that loop would mean an operator pressing a
     * button to confirm that a PDF renderer did its job.
     */
    public function autoGenerate(): bool;
}
