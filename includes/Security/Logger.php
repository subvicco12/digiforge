<?php
declare(strict_types=1);
namespace DigiForge\Security;
use DigiForge\Database\Tables;
/** Append-only audit writer. Context is recursively redacted before persistence. */
final class Logger {
    private const REDACT = ['password','secret','token','accesstoken','refreshtoken','clientsecret','authorization','apikey','credential','privatekey','signingkey'];
    public static function audit(string $event, array $context = [], string $object_type = '', string $object_id = ''): void { do_action('digiforge_log', $event, $context, $object_type, $object_id); }
    public static function write(string $event, array $context = [], string $object_type = '', string $object_id = ''): void {
        global $wpdb; $wpdb->insert(Tables::audit_log(), ['event_type' => sanitize_key($event), 'actor_id' => get_current_user_id(), 'object_type' => sanitize_key($object_type), 'object_id' => sanitize_text_field($object_id), 'context' => wp_json_encode(self::redact($context)), 'created_at' => current_time('mysql', true)], ['%s','%d','%s','%s','%s','%s']);
    }
    public static function redact(array $context): array {
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
