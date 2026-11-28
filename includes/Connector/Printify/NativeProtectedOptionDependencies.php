<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;

/** Sideband evidence from fixed protected business getters, never an authority or SQL proxy. */
final class NativeProtectedOptionDependencies
{
    private const MAX_ROOTS = 96;
    private const MAX_TOTAL_BYTES = 67108864;
    private static array $frames = [];

    public static function capture(callable $reader): array|\WP_Error
    {
        if (count(self::$frames) >= 4) return NativeFailure::error('source_data_capture_depth');
        $id = bin2hex(random_bytes(8));
        self::$frames[$id] = ['dependencies'=>[], 'bytes'=>0, 'invalid'=>false];
        try {
            $value = $reader();
            if (is_wp_error($value)) return $value;
            $frame = self::$frames[$id];
            if (!is_array($value) || $frame['invalid'] || $frame['dependencies'] === []) return NativeFailure::error('source_data_capture_invalid');
            $dependencies = $frame['dependencies'];
            ksort($dependencies, SORT_STRING);
            return ['value'=>$value, 'dependencies'=>$dependencies];
        } catch (\Throwable) {
            return NativeFailure::error('source_data_capture_unavailable');
        } finally {
            unset(self::$frames[$id]);
        }
    }

    /** Getters pass the exact raw value they just read; no value is retained or exported. */
    public static function observeOption(string $name, mixed $raw): void
    {
        if (self::$frames === []) return;
        $length = is_string($raw) ? strlen($raw) : 0;
        if (!self::name($name) || ($raw !== null && !is_string($raw)) || $length > self::MAX_TOTAL_BYTES) {
            foreach (array_keys(self::$frames) as $id) self::$frames[$id]['invalid'] = true;
            return;
        }
        $eligible = [];
        foreach (array_keys(self::$frames) as $id) {
            if (self::$frames[$id]['invalid']) continue;
            $new = !array_key_exists($name, self::$frames[$id]['dependencies']);
            if (($new && count(self::$frames[$id]['dependencies']) >= self::MAX_ROOTS) || ($new && self::$frames[$id]['bytes'] + $length > self::MAX_TOTAL_BYTES)) {
                self::$frames[$id]['invalid'] = true;
                continue;
            }
            $eligible[] = $id;
        }
        if ($eligible === []) return;
        $entry = ['exists'=>$raw !== null, 'sha256'=>is_string($raw) ? hash('sha256', $raw) : null];
        foreach ($eligible as $id) {
            $prior = self::$frames[$id]['dependencies'][$name] ?? null;
            if ($prior !== null && $prior !== $entry) self::$frames[$id]['invalid'] = true;
            if ($prior === null) self::$frames[$id]['bytes'] += $length;
            self::$frames[$id]['dependencies'][$name] = $entry;
        }
    }

    public static function lockCurrent(ShopBinding $scope, int $actor, array $expected): array|\WP_Error
    {
        if ($scope->shopKey !== 'personalized_pod' || $scope->shopId !== 2 || $expected === [] || count($expected) > self::MAX_ROOTS) return NativeFailure::error('source_data_identity');
        // Detach caller references before any callback-capable database call.
        $roots = [];
        foreach ($expected as $name=>$entry) {
            if (!is_string($name) || !self::name($name) || !is_array($entry) || count($entry) !== 2 || array_diff(array_keys($entry), ['exists','sha256']) !== [] || !is_bool($entry['exists'] ?? null)) return NativeFailure::error('source_data_contract');
            $hash = $entry['sha256'] ?? null;
            if (($entry['exists'] && (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1)) || (!$entry['exists'] && $hash !== null)) return NativeFailure::error('source_data_contract');
            $roots[$name] = ['exists'=>$entry['exists'], 'sha256'=>$hash];
        }
        ksort($roots, SORT_STRING);
        $authority = NativeAuthorityAdapter::scope($scope, $actor, ['manage_digiforge_pod','manage_digiforge_products','manage_digiforge_listings','manage_digiforge_research']);
        if (is_wp_error($authority)) return $authority;
        global $wpdb;
        try {
            $database = $wpdb;
            $connectionId = NativeSourceTransaction::id();
            if (is_wp_error($connectionId)) return $connectionId;
            $wpdb->last_error = '';
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $wpdb->options));
            $ddl = $wpdb->get_row('SHOW CREATE TABLE '.$wpdb->options, ARRAY_N);
            if ($wpdb->last_error !== '' || strtoupper((string)$engine) !== 'INNODB' || !is_array($ddl) || !str_starts_with(strtoupper((string)($ddl[1] ?? '')), 'CREATE TABLE ')) return NativeFailure::error('source_data_storage');
            $indexes = $wpdb->get_results('SHOW INDEX FROM '.$wpdb->options, ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($indexes) || count($indexes) > 64) return NativeFailure::error('source_data_unique_index');
            $keys = [];
            foreach ($indexes as $index) {
                if (!is_array($index) || array_diff(['Key_name','Non_unique','Seq_in_index','Column_name','Sub_part'], array_keys($index)) !== [] || !is_string($index['Key_name'])) return NativeFailure::error('source_data_unique_index');
                $keys[$index['Key_name']][] = $index;
            }
            $unique = false;
            foreach ($keys as $columns) {
                $column = $columns[0];
                if (count($columns) === 1 && (string)($column['Non_unique'] ?? '') === '0' && (string)($column['Seq_in_index'] ?? '') === '1' && ($column['Column_name'] ?? null) === 'option_name' && ($column['Sub_part'] ?? null) === null) $unique = true;
            }
            if (!$unique) return NativeFailure::error('source_data_unique_index');
            foreach ($roots as $name=>$entry) {
                $wpdb->last_error = '';
                $rows = $wpdb->get_results($wpdb->prepare('SELECT option_name,SHA2(option_value,256) AS value_hash FROM '.$wpdb->options.' WHERE option_name=%s ORDER BY option_id LIMIT 2 FOR UPDATE', $name), ARRAY_A);
                if ($wpdb->last_error !== '' || !is_array($rows) || count($rows) > 1) return NativeFailure::error('source_data_unavailable');
                if (!$entry['exists']) {
                    if ($rows !== []) return NativeFailure::error('source_data_drift');
                } elseif (count($rows) !== 1 || !is_string($rows[0]['option_name'] ?? null) || !hash_equals($name, $rows[0]['option_name']) || !is_string($rows[0]['value_hash'] ?? null) || !hash_equals($entry['sha256'], $rows[0]['value_hash'])) {
                    return NativeFailure::error('source_data_drift');
                }
            }
            $last = NativeAuthorityAdapter::scope($scope, $actor, ['manage_digiforge_pod','manage_digiforge_products','manage_digiforge_listings','manage_digiforge_research']);
            if (is_wp_error($last) || $last['scope'] !== $authority['scope'] || $last['session_fingerprint'] !== $authority['session_fingerprint']) return NativeFailure::error('source_data_authority_changed');
            $transaction = NativeSourceTransaction::verify($database, $connectionId);
            if (is_wp_error($transaction)) return $transaction;
            return ['schema'=>'DF_NATIVE_LOCKED_DATA_OPTIONS_1','scope_fingerprint'=>$scope->fingerprint(),'actor_id'=>$actor,
                'dependency_count'=>count($roots),'dependency_hash'=>NativePortContract::hash($roots),
                'source_data_options_locked'=>true,'authority_roots_locked'=>false,'source_approval_cas_verified'=>false,
                'native_product_version'=>null,'transaction_owned_by_caller'=>true,'dispatch_permitted'=>false,
                'external_execution_authorized'=>false,'release_hold'=>true];
        } catch (\Throwable) {
            return NativeFailure::error('source_data_lock_unavailable');
        }
    }

    private static function name(string $name): bool
    {
        if (strlen($name) > 240 || preg_match('/password|secret|token|credential|authorization/i', $name)) return false;
        return preg_match('/^(?:digiforge_s2_media_|digiforge_pp_plan_|digiforge_product_package_|digiforge_package_reconciliation_|digiforge_opportunity_personalized_pod_|digiforge_commercial_version_|digiforge_design_personalized_pod_|digiforge_shop2_workflow_|digiforge_pod_intelligence_v1_|digiforge_shop2_personalization_|digiforge_shop2_proof_intake_)[A-Za-z0-9_:-]{1,190}$/D', $name) === 1;
    }
}
