<?php
declare(strict_types=1);
namespace DigiForge\Database;

/** Readiness must prove the full component contract before a monotonic marker advance. */
final class ComponentSchema
{
    public static function ensure(string $table, int $version, string $sql, string $error): bool
    {
        global $wpdb;
        try {
            $current = self::marker();
            if ($current === false) { return self::failure($table, $error, 'marker_unavailable'); }
            $state = self::inspect($table, $sql);
            if ($state === 'ABSENT') {
                $wpdb->last_error = '';
                $created = $wpdb->query(str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $sql));
                if ($created === false || $wpdb->last_error !== '') { return self::failure($table, $error, 'create_failed'); }
                $state = self::inspect($table, $sql);
            }
            if ($state !== 'READY') { return self::failure($table, $error, $state); }
            return self::advance($version) || self::failure($table, $error, 'marker_write_failed');
        } catch (\Throwable) {
            return self::failure($table, $error, 'inspection_failed');
        }
    }

    /** @return int|false Zero denotes a missing initial marker; corrupt/read-error markers deny. */
    private static function marker(): int|false
    {
        global $wpdb;
        $wpdb->last_error = '';
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", 'digiforge_db_schema_version'));
        if ($wpdb->last_error !== '') { return false; }
        if ($raw === null) { return 0; }
        if (!is_string($raw) || preg_match('/^(0|[1-9][0-9]{0,8})$/D', $raw) !== 1) { return false; }
        return (int)$raw;
    }

    private static function advance(int $version): bool
    {
        global $wpdb;
        $current = self::marker();
        if ($current === false) { return false; }
        if ($current >= $version) { return true; }
        // Atomic max also covers another migration advancing between our read and write.
        // Corrupt concurrent values are retained and then rejected, never cast into authority.
        $wpdb->last_error = '';
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=IF(option_value REGEXP '^(0|[1-9][0-9]{0,8})$',GREATEST(CAST(option_value AS UNSIGNED),CAST(VALUES(option_value) AS UNSIGNED)),option_value)",
            'digiforge_db_schema_version', (string)$version
        ));
        if ($result === false || $wpdb->last_error !== '') { return false; }
        wp_cache_delete('digiforge_db_schema_version', 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        $observed = self::marker();
        // The legacy digiforge_db_version belongs to the core migrator, not this component.
        return $observed !== false && $observed >= $version;
    }

    /** Full columns, named index order/uniqueness/prefixes and transactional engine. */
    public static function inspect(string $table, string $sql): string
    {
        global $wpdb;
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) { return 'UNAVAILABLE'; }
        $previous = $wpdb->suppress_errors(true);
        try {
            $wpdb->last_error = '';
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table));
            if ($wpdb->last_error !== '') { return 'UNAVAILABLE'; }
            if ($engine === null) { return 'ABSENT'; }
            if (strtoupper((string)$engine) !== 'INNODB') { return 'INCOMPLETE'; }
            $wpdb->last_error = '';
            $columns = $wpdb->get_results("SHOW FULL COLUMNS FROM `$table`", ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($columns)) { return 'UNAVAILABLE'; }
            $wpdb->last_error = '';
            $indexes = $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($indexes)) { return 'UNAVAILABLE'; }
            $byColumn = [];
            foreach ($columns as $column) { $byColumn[$column['Field']] = $column; }
            $byIndex = [];
            foreach ($indexes as $index) { $byIndex[$index['Key_name']][(int)$index['Seq_in_index']] = $index; }
            foreach (explode("\n", $sql) as $line) {
                $line = trim(rtrim($line, ','));
                if ($line === '' || str_starts_with($line, 'CREATE TABLE ') || str_starts_with($line, ')')) { continue; }
                if (preg_match('/^(PRIMARY KEY|UNIQUE KEY ([a-z0-9_]+)|KEY ([a-z0-9_]+))\s*\((.+)\)$/iD', $line, $match)) {
                    $name = $match[1] === 'PRIMARY KEY' ? 'PRIMARY' : ($match[2] !== '' ? $match[2] : $match[3]);
                    $unique = str_starts_with($match[1], 'UNIQUE') || $name === 'PRIMARY';
                    $parts = explode(',', $match[4]);
                    if (!isset($byIndex[$name]) || count($parts) !== count($byIndex[$name])) { return 'INCOMPLETE'; }
                    foreach ($parts as $i => $part) {
                        if (!preg_match('/^\s*([a-z0-9_]+)(?:\(([0-9]+)\))?\s*$/iD', $part, $key)) { return 'UNAVAILABLE'; }
                        $actual = $byIndex[$name][$i + 1] ?? [];
                        if (($actual['Column_name'] ?? null) !== $key[1] || (int)($actual['Non_unique'] ?? -1) !== ($unique ? 0 : 1)
                            || (int)($actual['Sub_part'] ?? 0) !== (int)($key[2] ?? 0)) { return 'INCOMPLETE'; }
                    }
                    continue;
                }
                if (!preg_match('/^([a-z0-9_]+)\s+(.+?)(?=\s+(?:NOT NULL|NULL|DEFAULT|AUTO_INCREMENT)|$)(.*)$/iD', $line, $match)) { return 'UNAVAILABLE'; }
                $actual = $byColumn[$match[1]] ?? null;
                if (!is_array($actual) || self::type($actual['Type']) !== self::type($match[2])) { return 'INCOMPLETE'; }
                $notNull = str_contains(strtoupper($match[3]), 'NOT NULL');
                $auto = str_contains(strtoupper($match[3]), 'AUTO_INCREMENT');
                if (($actual['Null'] === 'NO') !== $notNull || str_contains(strtolower($actual['Extra']), 'auto_increment') !== $auto) { return 'INCOMPLETE'; }
                $default = null;
                if (preg_match("/DEFAULT\\s+('(?:''|[^'])*'|[^\\s,]+)/i", $match[3], $value)) { $default = trim($value[1], "'"); }
                if ($actual['Default'] !== $default) { return 'INCOMPLETE'; }
            }
            return 'READY';
        } finally { $wpdb->suppress_errors($previous); }
    }

    private static function type(string $type): string
    {
        return (string)preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\([0-9]+\)/', '$1', strtolower(trim($type)));
    }

    private static function failure(string $table, string $error, string $state): bool
    {
        update_option('digiforge_last_migration_failure', ['error_code' => $error, 'table_name' => $table, 'schema_state' => $state, 'occurred_at' => current_time('mysql', true)], false);
        return false;
    }
}
