<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

/** Verifies evidence only. No runtime issuer pin, registrar or dispatch capability is configured. */
final class HeldSignedEvidence
{
    private const DIGESTS = ['operation_id', 'correlation_id', 'idempotency_id', 'payload_digest', 'event_hash', 'epoch'];
    private const SCOPES = ['shop', 'account', 'integration'];
    private const REVISIONS = ['actor', 'session_generation', 'authorization_revision', 'policy_revision', 'journal_revision', 'sequence'];

    /**
     * The consumer must supply a trusted pin and current expected identity.
     * True describes signature and identity only, without one-use admission or revocation ordering.
     */
    public static function matches(
        string $wire, string $keyId, string $issuer, string $audience, string $publicKey,
        bool $revoked, int $now, array $expected
    ): bool {
        if ($revoked || strlen($wire) > 4096 || strlen($publicKey) !== 32
            || !\function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        try {
            $envelope = json_decode($wire, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($envelope) || array_keys($envelope) !== ['key_id', 'manifest', 'signature']
                || $envelope['key_id'] !== $keyId || !is_array($envelope['manifest']) || !is_string($envelope['signature'])) {
                return false;
            }
            $manifest = $envelope['manifest'];
            $identity = [...self::DIGESTS, ...self::SCOPES, ...self::REVISIONS];
            $headers = ['version', 'issuer', 'audience', 'phase', 'issued', 'expires'];
            $fields = [...$identity, ...$headers];
            if (count($manifest) !== count($fields) || array_diff($fields, array_keys($manifest)) !== []
                || count($expected) !== count($identity) || array_diff($identity, array_keys($expected)) !== []
                || $manifest['version'] !== 1 || $manifest['issuer'] !== $issuer
                || $manifest['audience'] !== $audience || $manifest['phase'] !== 'SEND_ADMISSION') {
                return false;
            }
            foreach ($identity as $field) {
                if ($manifest[$field] !== $expected[$field]) {
                    return false;
                }
            }
            foreach (self::DIGESTS as $field) {
                if (!is_string($manifest[$field]) || preg_match('/^[a-f0-9]{64}$/D', $manifest[$field]) !== 1) {
                    return false;
                }
            }
            foreach ([...self::SCOPES, 'issuer', 'audience'] as $field) {
                if (!is_string($manifest[$field]) || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $manifest[$field]) !== 1) {
                    return false;
                }
            }
            foreach ([...self::REVISIONS, 'issued', 'expires'] as $field) {
                if (!is_int($manifest[$field]) || $manifest[$field] < 1 || $manifest[$field] > 9007199254740991) {
                    return false;
                }
            }
            if ($manifest['issued'] > $now || $manifest['expires'] <= $now || $manifest['expires'] - $manifest['issued'] > 30
                || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $keyId) !== 1) {
                return false;
            }
            ksort($manifest, SORT_STRING);
            $signed = ['key_id' => $keyId, 'manifest' => $manifest];
            $signature = base64_decode($envelope['signature'], true);
            $canonical = static fn(array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            return is_string($signature) && strlen($signature) === 64
                && base64_encode($signature) === $envelope['signature']
                && $canonical($signed + ['signature' => $envelope['signature']]) === $wire
                && sodium_crypto_sign_verify_detached($signature, $canonical($signed), $publicKey);
        } catch (\Throwable) {
            return false;
        }
    }
}
