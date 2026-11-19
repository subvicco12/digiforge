<?php
declare(strict_types=1);

namespace DigiForge\Connector\Printify;

/**
 * Disposable coordinator. The injected fixture port must use the pinned native
 * client to authenticate both envelopes and match the original control.
 * No HTTP registration, signer, activation or sender exists here.
 */
final class NativeProducerSynchronization
{
    public function __construct(private readonly NativeProducerPublicationJournal $journal)
    {
    }

    /**
     * @param list<mixed> $sourceArgs The seven canonical reviewed-writer inputs.
     * @param callable $blockedReadback Submit BLOCK once and return verified readback.
     * @param callable $prepareControl Build the exact signed PREPARE from committed bytes.
     * @param callable $preparedReadback Submit PREPARE once and return verified readback.
     */
    public function prepare(
        NativeBusinessVersionRepository $writer,
        array $sourceArgs,
        int $expectedVersion,
        string $expectedHash,
        string $mapping,
        string $operation,
        string $blockControl,
        callable $blockedReadback,
        callable $prepareControl,
        callable $preparedReadback
    ): array|\WP_Error {
        try {
            if (!$this->journal->claim($mapping, $operation, $blockControl)) {
                return self::held('claim');
            }
            $block = $blockedReadback($blockControl);
            if (!self::proof($block, 'DF_NATIVE_BLOCK_ATTESTATION_1', $mapping, 'state')
                || !$this->journal->blocked($mapping, $operation, json_encode($block, JSON_THROW_ON_ERROR))) {
                return self::held('block');
            }
            $written = $writer->recordReviewedVersion(...[
                ...$sourceArgs, $expectedVersion, $expectedHash, $this->journal, $mapping, $operation,
            ]);
            if (is_wp_error($written) || ($written['exact_replay'] ?? true) !== false) {
                return self::held('source_write');
            }
            $read = $writer->readCurrent(...$sourceArgs);
            if (is_wp_error($read) || $read['local_business_version_hash'] !== $written['local_business_version_hash']
                || $read['local_business_version_entity_id'] !== $written['local_business_version_entity_id']) {
                return self::held('source_readback');
            }
            $snapshot = $this->journal->confirmedSnapshot($mapping, $operation, $read['local_business_version_hash']);
            if ($snapshot === null) {
                return self::held('intent_readback');
            }
            $control = $prepareControl($snapshot, $block);
            if (!is_string($control) || !$this->journal->preparing($mapping, $operation, $control)) {
                return self::held('prepare_intent');
            }
            $proof = $preparedReadback($control);
            if (!self::proof($proof, 'DF_NATIVE_MAP_PUBLICATION_1', $mapping, 'mapping_state')
                || ($proof['phase'] ?? null) !== 'PREPARE'
                || !$this->journal->prepared($mapping, $operation, json_encode($proof, JSON_THROW_ON_ERROR))) {
                return self::held('prepare_readback');
            }
            return ['source' => $read, 'publication' => $proof, 'external_execution_authorized' => false];
        } catch (\Throwable) {
            return self::held('uncertain');
        }
    }

    private static function proof(mixed $proof, string $schema, string $mapping, string $stateField): bool
    {
        return is_array($proof) && ($proof['schema'] ?? null) === $schema
            && ($proof['version'] ?? null) === '1.0.0' && ($proof['mapping_id'] ?? null) === $mapping
            && ($proof[$stateField] ?? null) === 'BLOCKED' && ($proof['release_hold'] ?? null) === true
            && ($proof['external_execution_authorized'] ?? null) === false;
    }

    private static function held(string $code): \WP_Error
    {
        return new \WP_Error('native_producer_' . $code, 'Publication remains held for inspection.', [
            'outcome' => 'UNKNOWN', 'retry_permitted' => false, 'external_execution_authorized' => false,
        ]);
    }
}
