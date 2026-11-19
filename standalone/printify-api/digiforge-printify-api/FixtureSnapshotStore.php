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
            || $db->query('SELECT DATABASE()')->fetchColumn() !== 'printify_fixture'
            || $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='df_printify_fixture_snapshots'")->fetchColumn() !== 'InnoDB'
            || (string)$db->query('SELECT @@innodb_flush_log_at_trx_commit')->fetchColumn() !== '1'
            || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $account) !== 1
            || !Runtime::permitsFixtureShop($shop)) {
            throw new \InvalidArgumentException('Exact disposable fixture required.');
        }
    }

    /** An insert-only fixture observation; source identity is a synthetic label. */
    public function append(string $id, array $request, string $body, int $observedAt): bool
    {
        if ($this->db->inTransaction() || !Runtime::permitsFixtureShop($this->shop) || !self::id($id) || $observedAt < 1) {
            return false;
        }
        $result = Runtime::validateCatalog($this->shop, $request, $body);
        if ($result['state'] !== 'LOCAL_VALIDATION_PASSED') {
            return false;
        }
        try {
            $sql = $this->db->prepare('INSERT INTO df_printify_fixture_snapshots
                (snapshot_id,shop_id,account_reference,source_identity,endpoint_name,content_sha256,observed_at,evidence_state)
                VALUES (?,?,?,?,?,?,?,?)');
            return $sql->execute([$id,$this->shop,$this->account,'SYNTHETIC_PRINTIFY',
                $result['endpoint'],$result['snapshot_sha256'],$observedAt,'FRESHNESS_UNVERIFIED'])
                && $sql->rowCount() === 1;
        } catch (\PDOException) {
            return false;
        }
    }

    public function read(string $id): ?array
    {
        if ($this->db->inTransaction() || !Runtime::permitsFixtureShop($this->shop) || !self::id($id)) {
            return null;
        }
        $sql = $this->db->prepare('SELECT snapshot_id,shop_id,account_reference,source_identity,endpoint_name,
            content_sha256,observed_at,evidence_state FROM df_printify_fixture_snapshots
            WHERE snapshot_id=? AND shop_id=? AND account_reference=? LIMIT 2');
        $sql->execute([$id,$this->shop,$this->account]);
        $rows = $sql->fetchAll(\PDO::FETCH_ASSOC);
        return count($rows) === 1 ? $rows[0] : null;
    }

    private static function id(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
