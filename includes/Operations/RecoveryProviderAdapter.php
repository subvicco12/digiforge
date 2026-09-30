<?php

declare(strict_types=1);

namespace DigiForge\Operations;

/**
 * Contract for infrastructure recovery providers.
 *
 * Implementations may communicate with a hosting control plane, but must never
 * expose credentials, target the running site, or create recovery PASS evidence.
 */
interface RecoveryProviderAdapter
{
    public function providerSlug(): string;

    /** @return array{available:bool,reason:string} */
    public function capability(): array;

    /**
     * @param array<string,mixed> $plan
     * @return array{state:string,provider:string,provider_operation_reference?:string}|\WP_Error
     */
    public function execute(array $plan): array|\WP_Error;

    /**
     * @param array<string,mixed> $plan
     * @return array{state:string,provider:string,provider_operation_reference?:string}|\WP_Error
     */
    public function reconcile(array $plan): array|\WP_Error;
}
