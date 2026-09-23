<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Creates a fresh NOT_SENT replacement after a consumed pre-call authorization
 * where no external request attempt occurred.
 */
final class EtsyPreCallReplacementService
{
    public function __construct(private EtsyOperationRepository $operations) {}

    /** @return array<string,mixed>|WP_Error */
    public function create(
        int $sourceOperationId,
        array $prepared,
        string $newIdempotencyKey,
        string $newAuthorizationHash,
        string $newEvidenceHash,
        array $payload
    ): array|WP_Error {
        $source=$this->operations->find($sourceOperationId);
        if (!is_array($source)) return $this->error('source_not_found','Source Etsy operation was not found.');

        $recovery=EtsyPreCallRecoveryPlan::build($source,$prepared);
        if ($recovery instanceof WP_Error) return $recovery;

        $newIdempotencyKey=trim($newIdempotencyKey);
        $newAuthorizationHash=strtolower(trim($newAuthorizationHash));
        $newEvidenceHash=strtolower(trim($newEvidenceHash));
        if ($newIdempotencyKey==='' || strlen($newIdempotencyKey)>191) {
            return $this->error('idempotency','A fresh bounded idempotency key is required.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/',$newAuthorizationHash) || !preg_match('/^[a-f0-9]{64}$/',$newEvidenceHash)) {
            return $this->error('binding','Fresh authorization and evidence hashes are required.');
        }
        if (hash_equals((string)$recovery['prior_authorization_hash'],$newAuthorizationHash)) {
            return $this->error('authorization_reuse','Consumed authorization cannot be reused.');
        }
        if (hash_equals((string)($source['evidence_hash']??''),$newEvidenceHash)) {
            return $this->error('evidence_reuse','Replacement requires fresh authorization evidence.');
        }
        if (strcasecmp((string)($source['idempotency_key']??''),$newIdempotencyKey)===0) {
            return $this->error('idempotency_reuse','Replacement idempotency key must be new.');
        }

        $prior=$this->operations->byKey((string)($source['shop_reference']??''),$newIdempotencyKey);
        if (is_array($prior)) return $this->error('idempotency_reuse','Replacement idempotency key has already been used in this shop.');

        $input=[
            'shop_reference'=>(string)($source['shop_reference']??''),
            'intent_id'=>(int)($source['intent_id']??0),
            'draft_package_id'=>(int)($source['draft_package_id']??0),
            'operation_type'=>(string)($source['operation_type']??''),
            'idempotency_key'=>$newIdempotencyKey,
            'authorization_hash'=>$newAuthorizationHash,
            'evidence_hash'=>$newEvidenceHash,
        ];
        $created=$this->operations->createFromPayload($input,$payload);
        if ($created instanceof WP_Error) return $created;

        if (!empty($created['idempotent_replay'])
            || (string)($created['state']??'')!==EtsyOperationLifecycle::NOT_SENT
            || (int)($created['id']??0)===$sourceOperationId
            || !hash_equals((string)($created['authorization_hash']??''),$newAuthorizationHash)
            || !hash_equals((string)($created['evidence_hash']??''),$newEvidenceHash)) {
            return $this->error('fresh_operation_required','Pre-call recovery did not create a fresh NOT_SENT replacement.');
        }

        return [
            'state'=>'ETSY_PRECALL_REPLACEMENT_CREATED',
            'source_operation_id'=>$sourceOperationId,
            'operation'=>$created,
            'source_operation_superseded_for_execution'=>true,
            'reuse_prior_authorization'=>false,
            'reuse_prior_idempotency_key'=>false,
            'new_nonce_required'=>true,
            'adapter_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_precall_replacement_'.$code,$message,['status'=>409]);
    }
}
