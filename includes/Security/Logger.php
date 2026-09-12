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
            do_action('digiforge_audit_write_failed', self::$lastFailure);
            error_log('DigiForge audit persistence failed: AUDIT_WRITE_FAILED');
            return false;
        }

        self::$lastFailure = null;
        return true;
    }

    /** @return array<string, string>|null */
    public static function lastFailure(): ?array
    {
        return self::$lastFailure;
    }

    public static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $key));
            if (in_array($normalized, self::REDACT, true)) {
                $context[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }

        return $context;
    }
}
