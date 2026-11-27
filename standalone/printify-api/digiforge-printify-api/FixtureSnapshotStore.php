<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

/** Explicit disposable InnoDB storage. No schema creation or authority state. */
final class FixtureSnapshotStore
{
    public function __construct(private readonly \PDO $db, private readonly string $shop, private readonly string $account)
    {
        if (!\defined('DIGIFORGE_PRINTIFY_DISPOSABLE_STORE') || DIGIFORGE_PRINTIFY_DISPOSABLE_STORE !== true
            || !\function_exists('wp_get_environment_type') || \wp_get_environment_type() !== 'local'
            || $db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $db->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION
            || $db->inTransaction()
            || (string)$db->query('SELECT @@autocommit')->fetchColumn() !== '1'
            || $db->query('SELECT DATABASE()')->fetchColumn() !== 'printify_fixture'
            || $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='df_printify_fixture_snapshots'")->fetchColumn() !== 'InnoDB'
            || (string)$db->query('SELECT @@innodb_flush_log_at_trx_commit')->fetchColumn() !== '1'
            || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $account) !== 1
            || !Runtime::permitsFixtureAccount($shop, $account)) {
            throw new \InvalidArgumentException('Exact disposable fixture required.');
        }
    }

    /** An insert-only fixture observation; source identity is a synthetic label. */
    public function append(string $id, array $request, string $body, int $observedAt): bool
    {
        if ($this->db->inTransaction() || !Runtime::permitsFixtureAccount($this->shop, $this->account) || !self::id($id) || $observedAt < 1) {
            return false;
        }
        $result = Runtime::validateCatalog($this->shop, $request, $body);
        if ($result['state'] !== 'LOCAL_VALIDATION_PASSED') {
            return false;
        }
        return $this->insert($id, $result['endpoint'], $body, $observedAt, 'SYNTHETIC_PRINTIFY');
    }

    /** Authenticated fixture readback enters the same insert-only store. */
    public function appendAuthenticated(string $json, string $signature, array $request, string $body, array $expected, int $now): bool
    {
        if ($this->db->inTransaction() || ($expected['shop'] ?? null) !== $this->shop
            || ($expected['account_reference'] ?? null) !== $this->account) {
            return false;
        }
        require_once __DIR__ . '/SnapshotEvidence.php';
        $receipt = SnapshotEvidence::authenticate($json, $signature, $body, $expected, $now);
        if ($receipt['state'] !== 'AUTHENTICATED_OFFLINE_EVIDENCE') {
            return false;
        }
        $result = Runtime::validateCatalog($this->shop, $request, $body);
        if ($result['state'] !== 'LOCAL_VALIDATION_PASSED'
            || !hash_equals($receipt['record']['request_sha256'], $result['request_sha256'])) {
            return false;
        }
        // One observation per signed operation within its exact account/shop scope.
        $id = $this->operationSnapshotId($receipt['record']['operation_id']);
        return $this->insert($id, $result['endpoint'], $body, $receipt['record']['observed_at'],
            'SIGNED_SYNTHETIC', $json, $signature);
    }

    private function insert(string $id,string $endpoint,string $body,int $observedAt,string $source,?string $attestation=null,?string $signature=null): bool
    {
        try {
            $sql = $this->db->prepare('INSERT INTO df_printify_fixture_snapshots
                (snapshot_id,shop_id,account_reference,source_identity,endpoint_name,content_sha256,snapshot_body,observed_at,evidence_state,attestation,signature)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            return $sql->execute([$id,$this->shop,$this->account,$source,
                $endpoint,hash('sha256',$body),$body,$observedAt,'FRESHNESS_UNVERIFIED',$attestation,$signature])
                && $sql->rowCount() === 1;
        } catch (\PDOException) {
            return false;
        }
    }

    public function read(string $id): ?array
    {
        $row = $this->readRow($id);
        if ($row === null || $row['source_identity'] !== 'SYNTHETIC_PRINTIFY'
            || $row['attestation'] !== null || $row['signature'] !== null) {
            return null;
        }
        unset($row['snapshot_body'], $row['attestation'], $row['signature']);
        return $row;
    }

    public function readAuthenticated(array $request, array $expected, int $now): ?array
    {
        $operation = $expected['operation_id'] ?? null;
        if (($expected['shop'] ?? null) !== $this->shop || ($expected['account_reference'] ?? null) !== $this->account
            || !is_string($operation) || !self::id($operation)) {
            return null;
        }
        $id = $this->operationSnapshotId($operation);
        $row = $this->readRow($id);
        if ($row === null || $row['source_identity'] !== 'SIGNED_SYNTHETIC'
            || !is_string($row['attestation']) || !is_string($row['signature'])) {
            return null;
        }
        require_once __DIR__ . '/SnapshotEvidence.php';
        $receipt = SnapshotEvidence::authenticate($row['attestation'], $row['signature'], $row['snapshot_body'], $expected, $now);
        $result = Runtime::validateCatalog($this->shop, $request, $row['snapshot_body']);
        if ($receipt['state'] !== 'AUTHENTICATED_OFFLINE_EVIDENCE'
            || $result['state'] !== 'LOCAL_VALIDATION_PASSED'
            || $row['endpoint_name'] !== $result['endpoint']
            || (int)$row['observed_at'] !== $receipt['record']['observed_at']
            || !hash_equals($receipt['record']['request_sha256'], $result['request_sha256'])) {
            return null;
        }
        return $receipt + ['snapshot_id' => $id];
    }

    private function operationSnapshotId(string $operation): string
    {
        return hash('sha256', json_encode([$this->account, $this->shop, $operation], JSON_THROW_ON_ERROR));
    }

    private function readRow(string $id): ?array
    {
        if ($this->db->inTransaction() || !Runtime::permitsFixtureAccount($this->shop, $this->account) || !self::id($id)) {
            return null;
        }
        try {
            $sql = $this->db->prepare('SELECT snapshot_id,shop_id,account_reference,source_identity,endpoint_name,
                content_sha256,snapshot_body,observed_at,evidence_state,attestation,signature FROM df_printify_fixture_snapshots
                WHERE snapshot_id=? AND shop_id=? AND account_reference=? LIMIT 2');
            $sql->execute([$id, $this->shop, $this->account]);
            $rows = $sql->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
        if (count($rows) !== 1 || $rows[0]['snapshot_id'] !== $id || $rows[0]['shop_id'] !== $this->shop
            || $rows[0]['account_reference'] !== $this->account
            || !hash_equals($rows[0]['content_sha256'], hash('sha256', $rows[0]['snapshot_body']))) {
            return null;
        }
        return $rows[0];
    }

    private static function id(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
