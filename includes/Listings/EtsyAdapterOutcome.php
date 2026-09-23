<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Validates and normalizes the result returned by a future Etsy execution adapter.
 *
 * This is a pure local contract. It performs no HTTP, persistence, retries,
 * credential access, or lifecycle mutation.
 */
final class EtsyAdapterOutcome
{
    /** @return array<string,mixed>|WP_Error */
    public static function normalize(array $result): array|WP_Error
    {
        $state = strtoupper(trim((string)($result['state'] ?? '')));
        if ($state === 'CONFIRMED_SUCCESS') {
            $reference = trim((string)($result['external_reference'] ?? ''));
            if ($reference === '' || strlen($reference) > 191) {
                return self::error('success_reference', 'Confirmed Etsy success requires a bounded external reference.');
            }
            return [
                'state' => EtsyOperationLifecycle::CONFIRMED_SUCCESS,
                'external_reference' => $reference,
                'retry_permitted' => false,
                'reconciliation_required' => false,
            ];
        }

        if ($state === 'CONFIRMED_FAILURE') {
            $category = sanitize_key((string)($result['failure_category'] ?? ''));
            $code = sanitize_key((string)($result['failure_code'] ?? ''));
            if ($category === '' || $code === '') {
                return self::error('failure_evidence', 'Confirmed Etsy failure requires category and code evidence.');
            }
            return [
                'state' => EtsyOperationLifecycle::CONFIRMED_FAILURE,
                'failure_category' => $category,
                'failure_code' => $code,
                'retry_permitted' => EtsyOperationLifecycle::retryPermitted(EtsyOperationLifecycle::CONFIRMED_FAILURE),
                'reconciliation_required' => false,
            ];
        }

        if ($state === 'UNKNOWN') {
            return [
                'state' => EtsyOperationLifecycle::UNKNOWN,
                'retry_permitted' => false,
                'reconciliation_required' => true,
            ];
        }

        return self::error('state', 'Adapter result must be CONFIRMED_SUCCESS, CONFIRMED_FAILURE, or UNKNOWN.');
    }

    private static function error(string $code, string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_adapter_outcome_'.$code, $message, ['status' => 409]);
    }
}
