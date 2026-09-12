<?php

declare(strict_types=1);

namespace DigiForge\Security;

use DigiForge\Database\Tables;

/** Append-oriented audit writer with redaction and observable failure state. */
final class Logger
{
    private const REDACT = ['password', 'secret', 'token', 'accesstoken', 'refreshtoken', 'clientsecret', 'authorization', 'apikey', 'credential', 'privatekey', 'signingkey'];

    /** @var array<string, string>|null */
    private static ?array $lastFailure = null;

    public static function audit(string $event, array $context = [], string $objectType = '', string $objectId = ''): void
    {
        do_action('digiforge_log', $event, $context, $objectType, $objectId);
    }

    public static function write(string $event, array $context = [], string $objectType = '', string $objectId = ''): bool
    {
        global $wpdb;
        $ok = $wpdb->insert(
            Tables::audit_log(),
            [
                'event_type' => sanitize_key($event),
                'actor_id' => get_current_user_id(),
                'object_type' => sanitize_key($objectType),
                'object_id' => sanitize_text_field($objectId),
                'context' => wp_json_encode(self::redact($context)),
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s']
        );

        if ($ok === false) {
            self::$lastFailure = [
                'event_type' => sanitize_key($event),
                'occurred_at' => current_time('mysql', true),
                'error_code' => 'AUDIT_WRITE_FAILED',
            ];
            update_option('digiforge_last_audit_failure', self::$lastFailure, false);
            do_action('digiforge_audit_write_failed', self::$lastFailure);
            error_log('DigiForge audit persistence failed: AUDIT_WRITE_FAILED');
            return false;
        }

        self::$lastFailure = null;
        delete_option('digiforge_last_audit_failure');
        return true;
    }

    /** @return array<string, string>|null */
    public static function lastFailure(): ?array
    {
        if (self::$lastFailure !== null) {
            return self::$lastFailure;
        }

        $persisted = get_option('digiforge_last_audit_failure', null);
        return is_array($persisted) ? array_map('strval', $persisted) : null;
    }

    public static function isCredentialKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
        return in_array($normalized, self::REDACT, true);
    }

    public static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (self::isCredentialKey((string) $key)) {
                $context[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }

        return $context;
    }
}
