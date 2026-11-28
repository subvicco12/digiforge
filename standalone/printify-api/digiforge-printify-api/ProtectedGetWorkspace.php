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
    private const ARCHIVE_LIMIT = 64;
    private const ARCHIVE_BYTES = 4194304;
    // An encodable current row bounds the projection; reserve additional archive metadata.
    private const ARCHIVE_ENTRY_BYTES = self::MAX_BYTES + 2048;

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
        $history = self::recentReceipts($row['history'] ?? [],time());
        $row['history'] = array_map(static fn(array $item): array => array_intersect_key($item,
            ['at'=>true,'operation'=>true,'state'=>true,'reason'=>true]),$history);
        $row['provenance'] = array_values(array_column($history,'provenance'));
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
        return array_values(array_map(static function(array $entry): array {
            $record = array_intersect_key($entry,['at'=>true,'operation'=>true,'state'=>true,'reason'=>true]);
            $provenance = self::readProvenance($entry['provenance'] ?? null);
            if ($record['state']==='OBSERVED' && $provenance!==null
                && $provenance['operation']===$record['operation'] && $provenance['observed_at']<=$record['at']) {
                $record['provenance']=$provenance;
            }
            return $record;
        },$valid));
    }

    private static function readProvenance(mixed $value): ?array
    {
        $fields=['operation','source','observed_at','request_sha256','response_sha256','projection_sha256','observation_sha256'];
        if (!is_array($value) || array_keys($value)!==$fields
            || !is_string($value['operation']) || !isset(ProtectedGet::OPERATOR_FIELDS[$value['operation']])
            || !in_array($value['source'],['SYNTHETIC_PRINTIFY','SYNTHETIC_DIRECT_PRINTIFY','PRINTIFY_HTTPS_GET'],true)
            || !is_int($value['observed_at']) || $value['observed_at']<1) return null;
        foreach (array_slice($fields,3) as $key) {
            if (!is_string($value[$key]) || !preg_match('/^[a-f0-9]{64}$/D',$value[$key])) return null;
        }
        $identity=array_diff_key($value,['observation_sha256'=>true]);
        return hash_equals($value['observation_sha256'],ProtectedGet::digest($identity))?$value:null;
    }

    private static function observationProvenance(array $receipt,int $storedAt): ?array
    {
        if (!is_array($receipt['data']??null) || !is_int($receipt['observed_at']??null)
            || $receipt['observed_at']>$storedAt || $receipt['observed_at']<$storedAt-self::TTL) return null;
        $record=['operation'=>$receipt['operation']??null,'source'=>$receipt['source']??null,
            'observed_at'=>$receipt['observed_at'],'request_sha256'=>$receipt['request_digest']??null,
            'response_sha256'=>$receipt['response_sha256']??null,'projection_sha256'=>ProtectedGet::digest($receipt['data'])];
        return self::readProvenance($record+['observation_sha256'=>ProtectedGet::digest($record)]);
    }

    private static function archived(bool $direct,bool $lock=false): ?array
    {
        global $wpdb;
        $name=self::observationOption($direct).'_archive';
        $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s"
            .($lock?' FOR UPDATE':''),$name));
        if ($wpdb->last_error!=='' || ($raw!==null && (!is_string($raw) || strlen($raw)>self::ARCHIVE_BYTES))) return null;
        if ($raw===null) return [];
        try {
            $archive=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if (($archive['version']??null)!==1 || !is_array($archive['records']??null)
                || !array_is_list($archive['records']) || count($archive['records'])>self::ARCHIVE_LIMIT) return null;
            $previous=null;
            foreach ($archive['records'] as $record) {
                $evidence=self::readProvenance($record['provenance']??null);
                $fields = ['scope_sha256','stored_at','previous_sha256','provenance','projection'];
                if (array_key_exists('correlation_id', $record)) {
                    $fields[] = 'correlation_id';
                    if (!self::validCorrelation($record['correlation_id'])) return null;
                }
                $fields[] = 'record_sha256';
                if (array_keys($record) !== $fields
                    || !is_string($record['scope_sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$record['scope_sha256'])
                    || !is_int($record['stored_at']) || $record['stored_at']<1 || $record['previous_sha256']!==$previous
                    || $evidence===null || $evidence['observed_at']>$record['stored_at']
                    || !is_array($record['projection']) || $evidence['projection_sha256']!==ProtectedGet::digest($record['projection'])
                    || $record['record_sha256']!==ProtectedGet::digest(array_diff_key($record,['record_sha256'=>true]))) return null;
                $previous=$record['record_sha256'];
            }
            return $archive['records'];
        } catch (\Throwable) {
            return null;
        }
    }

    public static function linkedArtwork(array $scope,array $selection,bool $direct): bool
    {
        if (!self::available($direct)) return false;
        $observation = self::visible($scope, $direct);
        $receipt = $observation['receipt'] ?? null;
        if (($receipt['state'] ?? null) !== 'OBSERVED' || ($receipt['operation'] ?? null) !== 'PRODUCT_ARTWORK_READ'
            || !self::validCorrelation($receipt['correlation_id'] ?? null)) return false;
        $proof = self::observationProvenance($receipt, $observation['stored_at']);
        $now = time();
        $source = !$direct ? 'SYNTHETIC_PRINTIFY'
            : (DirectGet::synthetic() ? 'SYNTHETIC_DIRECT_PRINTIFY' : 'PRINTIFY_HTTPS_GET');
        if ($proof === null || $proof['source'] !== $source
            || $proof['observed_at'] < $now - 60 || $proof['observed_at'] > $now) return false;
        $records = self::archived($direct);
        $record = $records[array_key_last($records ?? [])] ?? null;
        if ($record === null || $record['scope_sha256'] !== ProtectedGet::digest($scope)
            || $record['stored_at'] !== $observation['stored_at']
            || ($record['correlation_id'] ?? null) !== $receipt['correlation_id']
            || $record['provenance'] !== $proof) return false;
        $data = $record['projection'];
        return ($data['product_id'] ?? null) === ($selection['product_id'] ?? null)
            && ($data['image_id'] ?? null) === ($selection['image_id'] ?? null) && !empty($data['placements']);
    }

    private static function validCorrelation(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) === 1;
    }

    public static function archiveStatus(bool $direct): string
    {
        $records = self::archived($direct);
        if ($records === null) return 'ARCHIVE_UNAVAILABLE';
        $bytes = json_encode(['version'=>1,'records'=>$records], JSON_THROW_ON_ERROR);
        return count($records) < self::ARCHIVE_LIMIT && strlen($bytes) <= self::ARCHIVE_BYTES - self::ARCHIVE_ENTRY_BYTES
            ? 'ARCHIVE_READY' : 'ARCHIVE_CAPACITY_EXHAUSTED';
    }

    public static function canArchive(bool $direct): bool
    {
        return self::archiveStatus($direct) === 'ARCHIVE_READY';
    }

    private static function lockName(bool $direct): string
    {
        global $wpdb;
        return hash('sha256', DB_NAME . ':' . $wpdb->options . ':' . self::observationOption($direct));
    }

    public static function ownsArchiveAdmission(bool $direct): bool
    {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(IS_USED_LOCK(%s)=CONNECTION_ID(),0)',
            self::lockName($direct))) === '1';
    }

    /**
     * One dispatch per archive at a time. This connection lock holds no transaction or row locks
     * across transport and disappears on connection termination.
     */
    public static function withArchiveAdmission(bool $direct, \Closure $run, mixed $busy): mixed
    {
        global $wpdb;
        if (!self::available($direct) || self::ownsArchiveAdmission($direct)) return $busy;
        $name = self::lockName($direct);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $name)) !== '1') return $busy;
        try {
            return $run();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    public static function archivedProvenance(array $scope,bool $direct=false): array
    {
        if (!self::available($direct) || ($direct && !DirectGet::gatesOpen())) return [];
        $scopeDigest=ProtectedGet::digest($scope);
        $result=[];
        foreach (self::archived($direct)??[] as $record) {
            if ($record['scope_sha256']!==$scopeDigest || $record['stored_at']>time()) continue;
            $result[]=$record['provenance']+['record_sha256'=>$record['record_sha256']];
        }
        return $result;
    }

    private static function appendObservation(array $scope,array $receipt,array $provenance,int $storedAt,bool $direct,int $connection): bool
    {
        global $wpdb;
        $records=self::archived($direct,true);
        if ($records===null || count($records)>=self::ARCHIVE_LIMIT) return false;
        $record=['scope_sha256'=>ProtectedGet::digest($scope),'stored_at'=>$storedAt,
            'previous_sha256'=>$records[array_key_last($records)]['record_sha256']??null,
            'provenance'=>$provenance,'projection'=>$receipt['data']];
        if (!self::validCorrelation($receipt['correlation_id'] ?? null)) return false;
        $record['correlation_id'] = $receipt['correlation_id'];
        if (strlen(json_encode($record, JSON_THROW_ON_ERROR)) > self::ARCHIVE_ENTRY_BYTES - 100) return false;
        $records[]=$record+['record_sha256'=>ProtectedGet::digest($record)];
        $payload=json_encode(['version'=>1,'records'=>$records],JSON_THROW_ON_ERROR);
        if (strlen($payload)>self::ARCHIVE_BYTES) return false;
        $changed=$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload)
            SELECT %s,%s,'no' FROM DUAL WHERE CONNECTION_ID()=%d AND @@session.in_transaction=1
            ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'",
            self::observationOption($direct).'_archive',$payload,$connection));
        return is_int($changed) && $changed>0;
    }

    public static function persist(array $scope, string $revision, array $receipt, bool $direct = false): bool
    {
        if (!self::available($direct)) return false;
        if (self::ownsArchiveAdmission($direct)) return self::persistUnderAdmission($scope, $revision, $receipt, $direct);
        return self::withArchiveAdmission($direct,
            static fn(): bool => self::persistUnderAdmission($scope, $revision, $receipt, $direct), false);
    }

    private static function persistUnderAdmission(array $scope, string $revision, array $receipt, bool $direct): bool
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
            if ($connection < 1 || !self::ownsArchiveAdmission($direct)
                || !AccountProfile::lockRevision($wpdb, $scope['revision'])) {
                return false;
            }
            $snapshot = self::snapshot($direct);
            if (($snapshot['revision'] ?? null) !== $revision || ($direct && !DirectGet::gatesOpen())) return false;
            $row = ['version'=>1,'scope_digest'=>ProtectedGet::digest($scope),'stored_at'=>time(),'receipt'=>$receipt];
            $previous = $snapshot['data'] ?? null;
            $sameScope = ($previous['scope_digest'] ?? null) === $row['scope_digest'];
            $history = $sameScope ? self::recentReceipts($previous['history'] ?? [],$row['stored_at']) : [];
            $entry = ['at'=>$row['stored_at']] + array_intersect_key($receipt,
                ['operation'=>true,'state'=>true,'reason'=>true]);
            if (($receipt['state']??null)==='OBSERVED') {
                $provenance=self::observationProvenance($receipt,$row['stored_at']);
                if ($provenance===null || !self::appendObservation($scope,$receipt,$provenance,$row['stored_at'],$direct,$connection)) return false;
                $entry['provenance']=$provenance;
            }
            $history[]=$entry;
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
