<?php
declare(strict_types=1);

namespace DigiForge\Connector\Printify;

/** Fixture-only persistence. The runtime does not create or migrate its table. */
final class NativeProducerPublicationJournal
{
    private string $table;

    public function __construct(private readonly \wpdb $db)
    {
        if (!defined('DIGIFORGE_DISPOSABLE_PROVENANCE') || DIGIFORGE_DISPOSABLE_PROVENANCE !== true
            || wp_get_environment_type() !== 'local') {
            throw new \LogicException('Disposable local provenance fixture required.');
        }
        $this->table = $db->prefix . 'digiforge_fixture_provenance';
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $this->table)) {
            throw new \LogicException('Invalid fixture table.');
        }
        $engine = $db->get_var($db->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $this->table
        ));
        if ($engine !== 'InnoDB' || (string) $db->get_var('SELECT @@innodb_flush_log_at_trx_commit') !== '1') {
            throw new \LogicException('Durable InnoDB fixture table required.');
        }
    }

    public function claim(string $mapping, string $operation, string $block): bool
    {
        return $this->idle() && self::digest($mapping) && self::digest($operation)
            && strlen($block) <= 65536 && $this->db->insert($this->table, [
                'mapping_id' => $mapping, 'operation_id' => $operation, 'block_control' => $block,
                'phase' => 'BLOCK_UNKNOWN',
            ]) === 1;
    }

    public function blocked(string $mapping, string $operation, string $proof): bool
    {
        return $this->transition($mapping, $operation, 'BLOCK_UNKNOWN', 'BLOCKED', ['block_proof' => $proof]);
    }

    public function claimedOn(\wpdb $db, string $mapping, string $operation): bool
    {
        return $db === $this->db && $this->db->get_var($this->db->prepare(
            "SELECT phase FROM {$this->table} WHERE mapping_id=%s AND operation_id=%s", $mapping, $operation
        )) === 'BLOCKED';
    }

    /** Called on the source writer's own connection before its confirmation COMMIT. */
    public function recordConfirmedIntent(\wpdb $db, string $mapping, string $operation, string $hash, string $snapshot): bool
    {
        return $db === $this->db && $this->transactionState() === '1' && self::digest($hash)
            && strlen($snapshot) <= 65536 && hash_equals($hash, hash('sha256', $snapshot))
            && $this->db->update($this->table, [
                'phase' => 'CONFIRMED', 'source_hash' => $hash, 'source_snapshot' => $snapshot,
            ], ['mapping_id' => $mapping, 'operation_id' => $operation, 'phase' => 'BLOCKED']) === 1;
    }

    public function confirmedSnapshot(string $mapping, string $operation, string $hash): ?string
    {
        if (!$this->idle()) {
            return null;
        }
        $snapshot = $this->db->get_var($this->db->prepare(
            "SELECT source_snapshot FROM {$this->table} WHERE mapping_id=%s AND operation_id=%s AND phase='CONFIRMED' AND source_hash=%s",
            $mapping, $operation, $hash
        ));
        return is_string($snapshot) && hash_equals($hash, hash('sha256', $snapshot)) ? $snapshot : null;
    }

    public function preparing(string $mapping, string $operation, string $control): bool
    {
        return $this->transition($mapping, $operation, 'CONFIRMED', 'PREPARE_UNKNOWN', ['prepare_control' => $control]);
    }

    public function prepared(string $mapping, string $operation, string $proof): bool
    {
        return $this->transition($mapping, $operation, 'PREPARE_UNKNOWN', 'PREPARED_BLOCKED', ['prepare_proof' => $proof]);
    }

    private function transition(string $mapping, string $operation, string $from, string $to, array $data): bool
    {
        return $this->idle() && strlen((string) reset($data)) <= 65536
            && $this->db->update($this->table, $data + ['phase' => $to], [
                'mapping_id' => $mapping, 'operation_id' => $operation, 'phase' => $from,
            ]) === 1;
    }

    public function idle(): bool
    {
        return $this->transactionState() === '0'
            && (string) $this->db->get_var('SELECT @@session.autocommit') === '1';
    }

    private function transactionState(): string
    {
        return (string) $this->db->get_var('SELECT @@session.in_transaction');
    }

    private static function digest(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
