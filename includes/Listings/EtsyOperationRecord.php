<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Validates canonical data before a future persistent Etsy operation ledger.
 * This class does not write to the database or contact Etsy.
 */
final class EtsyOperationRecord
{
    /** @return array<string,mixed>|WP_Error */
    public static function canonicalize(array $input): array|WP_Error
    {
        $required = ['shop_reference','intent_id','draft_package_id','operation_type','idempotency_key','request_fingerprint','authorization_hash','evidence_hash'];
        foreach ($required as $key) {
            if (!isset($input[$key]) || trim((string)$input[$key]) === '') {
                return new WP_Error('digiforge_etsy_operation_record', 'Missing required operation field.', ['status'=>400,'field'=>$key]);
            }
        }

        $shop = trim((string)$input['shop_reference']);
        $operation = strtoupper(trim((string)$input['operation_type']));
        $key = trim((string)$input['idempotency_key']);
        if (!preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $shop) || !preg_match('/^[A-Z_]{3,64}$/', $operation) || strlen($key) > 191) {
            return new WP_Error('digiforge_etsy_operation_record_format', 'Invalid operation identity.', ['status'=>400]);
        }
        foreach (['request_fingerprint','authorization_hash','evidence_hash'] as $hash) {
            if (!preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)$input[$hash])))) {
                return new WP_Error('digiforge_etsy_operation_record_hash', 'Invalid operation hash.', ['status'=>400,'field'=>$hash]);
            }
        }
        $intent = (int)$input['intent_id'];
        $package = (int)$input['draft_package_id'];
        if ($intent < 1 || $package < 1) {
            return new WP_Error('digiforge_etsy_operation_record_scope', 'Intent and draft package scope are required.', ['status'=>400]);
        }

        return [
            'shop_reference'=>$shop,
            'intent_id'=>$intent,
            'draft_package_id'=>$package,
            'operation_type'=>$operation,
            'state'=>EtsyOperationLifecycle::NOT_SENT,
            'idempotency_key'=>$key,
            'request_fingerprint'=>strtolower((string)$input['request_fingerprint']),
            'authorization_hash'=>strtolower((string)$input['authorization_hash']),
            'evidence_hash'=>strtolower((string)$input['evidence_hash']),
            'external_reference'=>'',
        ];
    }

    public static function identity(array $record): string
    {
        return hash('sha256', implode('|', [
            (string)($record['shop_reference'] ?? ''),
            (string)($record['intent_id'] ?? ''),
            (string)($record['draft_package_id'] ?? ''),
            (string)($record['operation_type'] ?? ''),
            (string)($record['idempotency_key'] ?? ''),
        ]));
    }
}
