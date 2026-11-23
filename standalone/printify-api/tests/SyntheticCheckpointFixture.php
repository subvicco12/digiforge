<?php
declare(strict_types=1);

/** Test-only verifier. The two injected readers are external to the disposable WordPress database. */
final class SyntheticCheckpointFixture
{
    private array $seen = [];

    public function __construct(private Closure $readWitness, private Closure $readAnchor)
    {
    }

    public function inspect(array $identity, array $observation): array
    {
        $denied = ['state'=>'DENIED', 'provider_execution_authorized'=>false, 'retry_permitted'=>false];
        $head = $observation['event_hash'] ?? null;
        $revision = $observation['journal_revision'] ?? null;
        if (($observation['state'] ?? null) !== 'UNKNOWN'
            || ($observation['operation_id'] ?? null) !== ($identity['operation_id'] ?? null)
            || ($observation['provider_account_binding_verified'] ?? null) !== true
            || !is_string($head) || !is_int($revision)) {
            return $denied;
        }

        $anchor = ($this->readAnchor)();
        $witness = ($this->readWitness)();
        $identityDigest = DigiForge\Connector\Printify\NativePortContract::hash($identity);
        $binding = [
            'identity_digest'=>$identityDigest,
            'journal_head'=>$head,
            'journal_revision'=>$revision,
        ];
        if ($anchor !== ['fixture'=>'rollback-anchor-v1'] + $binding
            || $witness !== ['fixture'=>'witness-v1', 'witness_revision'=>1] + $binding
            || isset($this->seen[$identityDigest])) {
            return $denied;
        }

        $this->seen[$identityDigest] = true;
        return ['state'=>'SYNTHETIC_CHECKPOINT_MATCHED', 'outcome'=>'UNKNOWN'] + $denied;
    }
}
