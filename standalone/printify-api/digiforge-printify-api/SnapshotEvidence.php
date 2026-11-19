<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

/** Authenticates exact observation bytes. It does not grant provider or native authority. */
final class SnapshotEvidence
{
    private const PURPOSE = "DF_PRINTIFY_SNAPSHOT_EVIDENCE_2\n";

    /** The expected context comes from the caller's current, trusted read context. */
    public static function authenticate(string $json, string $signature, string $body, array $expected, int $now): array
    {
        try {
            $pin = self::configuredPin();
            self::require($pin !== null && $now > 0 && strlen($signature) === 88 && strlen($json) <= 4096 && strlen($body) <= 262144);
            $proof = base64_decode($signature, true);
            self::require(is_string($proof) && strlen($proof) === SODIUM_CRYPTO_SIGN_BYTES
                && base64_encode($proof) === $signature);
            self::require(sodium_crypto_sign_verify_detached($proof, self::PURPOSE . $json, $pin));
            $record = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            self::require(is_array($record));
            $fields = ['schema','evidence_class','account_reference','shop','request_sha256',
                'content_sha256','source_sha','source_revision','operation_id','observed_at','expires_at'];
            self::require(array_keys($record) === $fields
                && json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) === $json);
            self::require($record['schema'] === 'DF_PRINTIFY_SNAPSHOT_EVIDENCE_2'
                && $record['evidence_class'] === 'SIGNED_SYNTHETIC');
            $join = ['account_reference','shop','request_sha256','source_sha','source_revision','operation_id'];
            self::require(count($expected) === count($join));
            foreach ($join as $key) {
                self::require(array_key_exists($key, $expected) && $expected[$key] === $record[$key]);
            }
            self::require(is_string($record['shop']) && is_string($record['account_reference'])
                && Runtime::permitsFixtureAccount($record['shop'], $record['account_reference']));
            foreach (['request_sha256','content_sha256','operation_id'] as $key) {
                self::require(is_string($record[$key]) && preg_match('/^[a-f0-9]{64}$/D', $record[$key]) === 1);
            }
            $source = \defined('DIGIFORGE_PRINTIFY_FIXTURE_SOURCE') ? \constant('DIGIFORGE_PRINTIFY_FIXTURE_SOURCE') : null;
            self::require(is_string($source) && preg_match('/^[a-f0-9]{40}$/D', $source) === 1
                && $record['source_sha'] === $source);
            $revision = \defined('DIGIFORGE_PRINTIFY_FIXTURE_REVISION') ? \constant('DIGIFORGE_PRINTIFY_FIXTURE_REVISION') : null;
            self::require(is_int($revision) && $revision > 0 && $record['source_revision'] === $revision);
            self::require(is_int($record['observed_at']) && is_int($record['expires_at'])
                && $record['observed_at'] > 0 && $record['observed_at'] <= $now && $now < $record['expires_at']
                && $record['expires_at'] - $record['observed_at'] <= 60
                && hash_equals($record['content_sha256'], hash('sha256', $body)));
            return [
                'state'=>'AUTHENTICATED_OFFLINE_EVIDENCE', 'record'=>$record,
                'evidence_sha256'=>hash('sha256', $json), 'provider_outcome_confirmed'=>false,
                'native_operation_mutated'=>false, 'retry_permitted'=>false, 'provider_execution_authorized'=>false,
            ];
        } catch (\Throwable) {
            return ['state'=>'DENIED','native_operation_mutated'=>false,
                'retry_permitted'=>false,'provider_execution_authorized'=>false];
        }
    }

    private static function configuredPin(): ?string
    {
        if (!\defined('DIGIFORGE_PRINTIFY_DISPOSABLE_STORE') || DIGIFORGE_PRINTIFY_DISPOSABLE_STORE !== true
            || !\function_exists('wp_get_environment_type') || \wp_get_environment_type() !== 'local'
            || !\function_exists('sodium_crypto_sign_verify_detached')
            || !\defined('DIGIFORGE_PRINTIFY_FIXTURE_EVIDENCE_KEY')) {
            return null;
        }
        $value = \constant('DIGIFORGE_PRINTIFY_FIXTURE_EVIDENCE_KEY');
        $bytes = is_string($value) ? base64_decode($value, true) : false;
        return is_string($bytes) && strlen($bytes) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && base64_encode($bytes) === $value ? $bytes : null;
    }

    private static function require(bool $valid): void
    {
        if (!$valid) {
            throw new \InvalidArgumentException('Snapshot evidence denied.');
        }
    }
}
