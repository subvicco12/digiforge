<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Builds a local-only reconciliation plan for an uncertain Etsy operation.
 *
 * It never calls Etsy, mutates lifecycle state, consumes authorization,
 * schedules work, or retries an external request.
 */
final class EtsyReconciliationPlan
{
    /** @return array<string,mixed>|WP_Error */
    public static function build(array $operation): array|WP_Error
    {
        $id = filter_var($operation['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            return self::error('operation_id', 'Reconciliation requires a persisted Etsy operation id.');
        }

        $state = $operation['state'] ?? null;
        if (!is_string($state) || $state !== EtsyOperationLifecycle::UNKNOWN) {
            return self::error('state', 'Only UNKNOWN Etsy operations may enter reconciliation planning.');
        }

        $shop = $operation['shop_reference'] ?? null;
        $key = $operation['idempotency_key'] ?? null;
        $fingerprint = $operation['request_fingerprint'] ?? null;
        if (!is_string($shop) || !is_string($key) || !is_string($fingerprint)) {
            return self::error('identity', 'Reconciliation requires string operation identity evidence.');
        }
        $shop = trim($shop);
        $key = trim($key);
        $fingerprint = strtolower(trim($fingerprint));
        if (
            $shop === '' || strlen($shop) > 191 ||
            $key === '' || strlen($key) > 191 ||
            !preg_match('/^[a-f0-9]{64}$/', $fingerprint)
        ) {
            return self::error('identity', 'Reconciliation operation identity evidence is invalid.');
        }

        return [
            'operation_id' => (int)$id,
            'from_state' => EtsyOperationLifecycle::UNKNOWN,
            'next_state' => EtsyOperationLifecycle::RECONCILIATION,
            'shop_reference' => $shop,
            'idempotency_key' => $key,
            'request_fingerprint' => $fingerprint,
            'provider_lookup_required' => true,
            'external_retry_permitted' => false,
            'adapter_invoked' => false,
            'external_execution_performed' => false,
        ];
    }

    private static function error(string $code, string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_reconciliation_'.$code, $message, ['status' => 409]);
    }
}
