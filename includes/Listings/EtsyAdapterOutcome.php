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
    private const MAX_EXTERNAL_REFERENCE_LENGTH = 191;
    private const MAX_FAILURE_EVIDENCE_LENGTH = 64;

    /** @return array<string,mixed>|WP_Error */
    public static function normalize(array $result): array|WP_Error
    {
        $stateRaw = $result['state'] ?? null;
        if (!is_string($stateRaw)) {
            return self::error('state', 'Adapter result state must be a string.');
        }
        $state = strtoupper(trim($stateRaw));

        if ($state === 'CONFIRMED_SUCCESS') {
            $referenceRaw = $result['external_reference'] ?? null;
            if (!is_string($referenceRaw)) {
                return self::error('success_reference', 'Confirmed Etsy success requires a string external reference.');
            }
            $reference = trim($referenceRaw);
            if ($reference === '' || strlen($reference) > self::MAX_EXTERNAL_REFERENCE_LENGTH) {
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
            $categoryRaw = $result['failure_category'] ?? null;
            $codeRaw = $result['failure_code'] ?? null;
            if (!is_string($categoryRaw) || !is_string($codeRaw)) {
                return self::error('failure_evidence', 'Confirmed Etsy failure evidence must be strings.');
            }
            $category = sanitize_key($categoryRaw);
            $code = sanitize_key($codeRaw);
            if (
                $category === '' ||
                $code === '' ||
                strlen($category) > self::MAX_FAILURE_EVIDENCE_LENGTH ||
                strlen($code) > self::MAX_FAILURE_EVIDENCE_LENGTH
            ) {
                return self::error('failure_evidence', 'Confirmed Etsy failure requires bounded category and code evidence.');
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
