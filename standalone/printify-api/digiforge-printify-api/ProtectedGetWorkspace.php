<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!defined('ABSPATH')) {
    exit;
}

/** One bounded, profile-fenced observation and redacted outcome history. */
final class ProtectedGetWorkspace
{
    private const OPTION = 'digiforge_printify_get_observation_v1';
    private const DIRECT_OPTION = 'digiforge_printify_direct_observation_v1';
    private const LIVE_OPTION = 'digiforge_printify_direct_provider_observation_v1';

    private static function observationOption(bool $direct): string
    {
        if (!$direct) return self::OPTION;
        return DirectGet::synthetic() ? self::DIRECT_OPTION : self::LIVE_OPTION;
    }
    private const TTL = 3600;
    private const MAX_BYTES = 524288;
    private const HISTORY_LIMIT = 8;

    public static function available(bool $direct = false): bool
    {
        global $wpdb;
        $selected = $direct ? DirectGet::selected() : (defined('DIGIFORGE_PRINTIFY_PROTECTED_GET_FIXTURE')
            && DIGIFORGE_PRINTIFY_PROTECTED_GET_FIXTURE === true);
        return $selected && $wpdb instanceof \wpdb
            && (($direct && !DirectGet::synthetic()) || (wp_get_environment_type() === 'local'
                && $wpdb->get_var('SELECT DATABASE()') === 'printify_fixture'))
            && strtoupper((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $wpdb->options))) === 'INNODB';
    }

    public static function snapshot(bool $direct = false): ?array
    {
        global $wpdb;
        if (!self::available($direct)) {
            return null;
        }
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::observationOption($direct)));
        if ($wpdb->last_error !== '' || ($raw !== null && (!is_string($raw) || strlen($raw) > self::MAX_BYTES))) {
            return null;
        }
        try {
            $data = $raw === null ? null : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if ($raw !== null && (!is_array($data) || ($data['version'] ?? null) !== 1
                || !is_int($data['stored_at'] ?? null))) {
                return null;
            }
            return ['revision'=>hash('sha256', $raw ?? ''),'data'=>$data];
        } catch (\JsonException) {
            return null;
        }
    }

    public static function visible(array $scope, bool $direct = false): ?array
    {
        $snapshot = self::snapshot($direct);
        $row = $snapshot['data'] ?? null;
        if (($row['scope_digest'] ?? null) !== ProtectedGet::digest($scope)
            || $row['stored_at'] > time() || time() - $row['stored_at'] > self::TTL) {
            return null;
        }
        $row['workspace_revision'] = $snapshot['revision'];
        $row['history'] = self::recentReceipts($row['history'] ?? [],time());
        return $row;
    }

    private static function recentReceipts(mixed $entries, int $now): array
    {
        if (!is_array($entries)) return [];
        $valid = array_filter(array_slice($entries,-self::HISTORY_LIMIT), static function ($entry) use ($now): bool {
            return is_array($entry) && isset($entry['at'],$entry['operation'],$entry['state'],$entry['reason'])
                && is_int($entry['at']) && $entry['at'] <= $now && $entry['at'] >= $now-self::TTL
                && is_string($entry['operation']) && preg_match('/^[A-Z_]{1,60}$/D',$entry['operation'])
                && is_string($entry['reason']) && preg_match('/^[A-Z_]{1,60}$/D',$entry['reason'])
                && in_array($entry['state'],['OBSERVED','UNAVAILABLE','BLOCKED','DENIED'],true);
        });
        return array_values(array_map(static fn(array $entry): array => array_intersect_key($entry,
            ['at'=>true,'operation'=>true,'state'=>true,'reason'=>true]), $valid));
    }

    public static function persist(array $scope, string $revision, array $receipt, bool $direct = false): bool
    {
        global $wpdb;
        if (!self::available($direct)
            || (string)$wpdb->get_var('SELECT @@session.in_transaction') !== '0'
            || $wpdb->query('START TRANSACTION') === false) {
            return false;
        }
        $committed = false;
        try {
            $connection = (int)$wpdb->get_var('SELECT CONNECTION_ID()');
            if ($connection < 1 || !AccountProfile::lockRevision($wpdb, $scope['revision'])) {
                return false;
            }
            $snapshot = self::snapshot($direct);
            if (($snapshot['revision'] ?? null) !== $revision || ($direct && !DirectGet::gatesOpen())) return false;
            $row = ['version'=>1,'scope_digest'=>ProtectedGet::digest($scope),'stored_at'=>time(),'receipt'=>$receipt];
            $previous = $snapshot['data'] ?? null;
            $sameScope = ($previous['scope_digest'] ?? null) === $row['scope_digest'];
            $history = $sameScope ? self::recentReceipts($previous['history'] ?? [],$row['stored_at']) : [];
            $history[] = ['at'=>$row['stored_at']] + array_intersect_key($receipt,
                ['operation'=>true,'state'=>true,'reason'=>true]);
            $row['history'] = array_slice($history,-self::HISTORY_LIMIT);
            if ($direct && $receipt['state'] !== 'OBSERVED' && $sameScope) {
                $success = ($previous['receipt']['state'] ?? null) === 'OBSERVED'
                    ? $previous['receipt'] : ($previous['last_success'] ?? null);
                if (is_array($success) && ($success['request_digest'] ?? null) === ($receipt['request_digest'] ?? null)
                    && $success['observed_at'] <= time() && time()-$success['observed_at'] <= self::TTL) {
                    $row['last_success'] = $success;
                }
            }
            $payload = json_encode($row, JSON_THROW_ON_ERROR);
            if (strlen($payload) > self::MAX_BYTES) {
                return false;
            }
            $changed = $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options}
                (option_name, option_value, autoload)
                SELECT %s, %s, 'no' FROM DUAL WHERE CONNECTION_ID()=%d AND @@session.in_transaction=1
                ON DUPLICATE KEY UPDATE option_value=VALUES(option_value), autoload='no'",
                self::observationOption($direct), $payload, $connection));
            $committed = is_int($changed) && $changed > 0 && $wpdb->query('COMMIT') !== false;
            return $committed;
        } catch (\Throwable) {
            return false;
        } finally {
            if (!$committed) {
                $wpdb->query('ROLLBACK');
            }
        }
    }
    public static function reserveDirect(array $scope, string $workspace, ?\Closure $permission = null): bool
    {
        global $wpdb;
        $permission ??= DirectGet::gatesOpen(...);
        if (!self::available(true) || !$permission()
            || (string)$wpdb->get_var('SELECT @@session.in_transaction') !== '0'
            || $wpdb->query('START TRANSACTION') === false) {
            return false;
        }
        $committed = false;
        try {
            $connection = (int)$wpdb->get_var('SELECT CONNECTION_ID()');
            if ($connection < 1 || !AccountProfile::lockRevision($wpdb, $scope['revision'])) return false;
            if ((self::snapshot(true)['revision'] ?? null) !== $workspace || !$permission()) return false;
            $name = 'digiforge_printify_direct_quota_'.ProtectedGet::digest(['account'=>$scope['account'],'shop'=>$scope['shop']]);
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",$name));
            if ($wpdb->last_error !== '' || ($raw !== null && (!is_string($raw) || strlen($raw)>300))) return false;
            $recent = $raw === null ? [] : json_decode($raw,true,3,JSON_THROW_ON_ERROR);
            $now = time();
            if (!is_array($recent) || !array_is_list($recent) || count($recent)>10) return false;
            foreach ($recent as $timestamp) if (!is_int($timestamp) || $timestamp>$now) return false;
            $recent = array_values(array_filter($recent, static fn(int $timestamp): bool => $timestamp>$now-60));
            if (count($recent)>=10) return false;
            $recent[] = $now;
            $changed = $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload)
                SELECT %s,%s,'no' FROM DUAL WHERE CONNECTION_ID()=%d AND @@session.in_transaction=1
                ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'",
                $name,json_encode($recent,JSON_THROW_ON_ERROR),$connection));
            $committed = is_int($changed) && $changed>0 && $wpdb->query('COMMIT') !== false;
            return $committed;
        } catch (\Throwable) {
            return false;
        } finally {
            if (!$committed) $wpdb->query('ROLLBACK');
        }
    }

}
