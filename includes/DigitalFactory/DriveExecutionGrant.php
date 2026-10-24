<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;

/**
 * Pure offline Google grant contract. This is NOT an issuer, a credential
 * store, a nonce consumer or permission to send HTTP requests.
 */
final class DriveExecutionGrant
{
    private const STAGES = [
        'folder', 'original_upload', 'original_readback',
        'native_conversion', 'native_readback', 'pdf_export',
        'native_final', 'reconcile_original_upload',
        'reconcile_native_conversion',
    ];

    public static function validate(
        #[\SensitiveParameter] array $grant,
        #[\SensitiveParameter] array $plan,
        string $stage,
        int $now,
        bool $revoked,
        bool $nonceAvailable
    ): bool {
        if ($revoked || !$nonceAvailable || $now < 1 || !in_array($stage, self::STAGES, true)) {
            return false;
        }
        foreach (['installation_sha256', 'environment', 'shop_key', 'folder_id',
                  'product_id', 'product_version_id', 'revision_id',
                  'source_sha256', 'id'] as $key) {
            if (!array_key_exists($key, $plan) || !array_key_exists($key, $grant)
                || !is_scalar($plan[$key]) || gettype($grant[$key]) !== gettype($plan[$key])
                || (string)$grant[$key] !== (string)$plan[$key]) {
                return false;
            }
        }
        foreach (['id', 'installation_sha256', 'source_sha256'] as $key) {
            if (!is_string($plan[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $plan[$key]) !== 1) {
                return false;
            }
        }
        foreach (['product_id', 'product_version_id', 'revision_id'] as $key) {
            if (!is_int($plan[$key] ?? null) || $plan[$key] < 1) { return false; }
        }
        if (!in_array($plan['environment'], ['local', 'development', 'staging', 'production'], true)) {
            return false;
        }
        if ($plan['shop_key'] !== 'digital'
            || $plan['folder_id'] !== DriveTransferRequest::FOLDER
            || !is_string($grant['issuer'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $grant['issuer']) !== 1
            || !is_string($grant['connection_id'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $grant['connection_id']) !== 1
            || !is_int($grant['actor_id'] ?? null) || $grant['actor_id'] < 1
            || ($grant['actor_id'] !== ($plan['actor_id'] ?? null))
            || !is_string($grant['nonce'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $grant['nonce']) !== 1
            || !is_int($grant['issued_at'] ?? null)
            || !is_int($grant['expires_at'] ?? null)
            || $grant['issued_at'] > $now
            || $grant['expires_at'] <= $now
            || $grant['expires_at'] - $grant['issued_at'] > 900
            || !is_int($grant['remaining_attempts'] ?? null)
            || $grant['remaining_attempts'] < 1
            || $grant['remaining_attempts'] > 14
            || !is_array($grant['stages'] ?? null)
            || !array_is_list($grant['stages'])
            || count($grant['stages']) < 1
            || count($grant['stages']) > count(self::STAGES)
            ) {
            return false;
        }
        foreach ($grant['stages'] as $allowed) {
            if (!is_string($allowed) || !in_array($allowed, self::STAGES, true)) {
                return false;
            }
        }
        return count(array_unique($grant['stages'])) === count($grant['stages'])
            && in_array($stage, $grant['stages'], true);
    }
}
