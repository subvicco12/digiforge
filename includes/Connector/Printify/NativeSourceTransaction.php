<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Inspect a caller-owned local SQL transaction; never start, finish or authorize one. */
final class NativeSourceTransaction
{
    public static function id(): string|\WP_Error
    {
        global $wpdb;
        try {
            $wpdb->last_error = '';
            $state = $wpdb->get_row('SELECT CONNECTION_ID() AS connection_id,@@session.in_transaction AS active,@@session.autocommit AS autocommit,@@innodb_flush_log_at_trx_commit AS flush_commit,@@log_bin AS binlog,@@sync_binlog AS sync_binlog,@@tx_isolation AS isolation_level', ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($state) || array_diff(['connection_id','active','autocommit','flush_commit','binlog','sync_binlog','isolation_level'], array_keys($state)) !== [] || !in_array((string)($state['binlog'] ?? ''), ['0','1'], true) || !preg_match('/^[0-9]+$/D', (string)($state['sync_binlog'] ?? '')) || !preg_match('/^[1-9][0-9]*$/D', (string)($state['connection_id'] ?? '')) || (string)($state['active'] ?? '') !== '1' || (string)($state['autocommit'] ?? '') !== '1' || (string)($state['flush_commit'] ?? '') !== '1' || ((string)($state['binlog'] ?? '') === '1' && (string)($state['sync_binlog'] ?? '') !== '1') || strtoupper((string)($state['isolation_level'] ?? '')) !== 'REPEATABLE-READ') return NativeFailure::error('source_transaction_required');
            return (string)$state['connection_id'];
        } catch (\Throwable) {
            return NativeFailure::error('source_transaction_unavailable');
        }
    }

    public static function verify(object $database, string $connectionId): true|\WP_Error
    {
        global $wpdb;
        if ($wpdb !== $database || preg_match('/^[1-9][0-9]*$/D', $connectionId) !== 1) return NativeFailure::error('source_transaction_changed');
        $current = self::id();
        if (is_wp_error($current)) return $current;
        return $wpdb === $database && hash_equals($connectionId, $current) ? true : NativeFailure::error('source_transaction_changed');
    }
}
