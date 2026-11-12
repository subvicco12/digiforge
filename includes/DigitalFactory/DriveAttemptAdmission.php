<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;

/**
 * Fail-closed, pure decision for a single Google Drive attempt.
 * No credentials, persistence, HTTP sender, or grant issuance.
 * The caller MUST obtain observations from trusted server-side sources.
 */
final class DriveAttemptAdmission
{
    public static function allowed(
        #[\SensitiveParameter] array $grant,
        #[\SensitiveParameter] array $plan,
        #[\SensitiveParameter] array $observed,
        string $stage
    ): bool {
        $now = $observed['now'] ?? null;
        if (!is_int($now)
            || ($observed['stop_all'] ?? true) !== false
            || ($observed['external_locked'] ?? true) !== false
            || ($observed['automation_armed'] ?? false) !== true
            || ($observed['release_authorized'] ?? false) !== true
            || ($observed['shop_access_current'] ?? false) !== true
            || ($observed['google_connection_current'] ?? false) !== true
            || ($observed['account_verified'] ?? false) !== true
            || ($observed['folder_access_verified'] ?? false) !== true
            || ($observed['credential_valid'] ?? false) !== true
            || ($observed['durable_nonce_claimed'] ?? false) !== true
            || ($observed['attempt_claimed'] ?? false) !== true
            || ($observed['grant_revoked'] ?? true) !== false
            || ($observed['connection_revoked'] ?? true) !== false
            || !is_int($observed['attempts_used'] ?? null)
            || $observed['attempts_used'] < 0
            || !is_int($grant['remaining_attempts'] ?? null)
            || $observed['attempts_used'] >= $grant['remaining_attempts']) {
            return false;
        }
        foreach (['issuer', 'connection_id'] as $key) {
            if (!is_string($observed[$key] ?? null)
                || !is_string($grant[$key] ?? null)
                || !hash_equals($grant[$key], $observed[$key])) {
                return false;
            }
        }
        return DriveExecutionGrant::validate(
            $grant, $plan, $stage, $now, false, true
        );
    }
}
