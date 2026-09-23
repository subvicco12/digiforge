<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Local factory for a retry operation after CONFIRMED_FAILURE.
 * It never reuses the consumed authorization or prior idempotency key.
 */
final class EtsyRetryOperationService
{
    public function __construct(private EtsyOperationRepository $operations) {}

    /** @return array<string,mixed>|WP_Error */
    public function create(
        int $sourceOperationId,
        string $newIdempotencyKey,
        string $newAuthorizationHash,
        string $newEvidenceHash,
        array $payload
    ): array|WP_Error {
        $source=$this->operations->find($sourceOperationId);
        if (!is_array($source)) return $this->error('source_not_found','Source Etsy operation was not found.');

        $plan=EtsyRetryPlan::build($source,$newIdempotencyKey);
        if ($plan instanceof WP_Error) return $plan;

        $newAuthorizationHash=strtolower(trim($newAuthorizationHash));
        $newEvidenceHash=strtolower(trim($newEvidenceHash));
        if (!preg_match('/^[a-f0-9]{64}$/',$newAuthorizationHash) || !preg_match('/^[a-f0-9]{64}$/',$newEvidenceHash)) {
            return $this->error('new_binding','Retry requires valid new authorization and evidence hashes.');
        }
        if (hash_equals((string)$plan['prior_authorization_hash'],$newAuthorizationHash)) {
            return $this->error('authorization_reuse','Retry authorization must be new.');
        }

        $input=[
            'shop_reference'=>(string)($source['shop_reference']??''),
            'intent_id'=>(int)($source['intent_id']??0),
            'draft_package_id'=>(int)($source['draft_package_id']??0),
            'operation_type'=>(string)($source['operation_type']??''),
            'idempotency_key'=>(string)$plan['new_idempotency_key'],
            'authorization_hash'=>$newAuthorizationHash,
            'evidence_hash'=>$newEvidenceHash,
        ];
        $created=$this->operations->createFromPayload($input,$payload);
        if ($created instanceof WP_Error) return $created;

        return [
            'state'=>'ETSY_RETRY_OPERATION_CREATED',
            'source_operation_id'=>$sourceOperationId,
            'operation'=>$created,
            'new_authorization_required'=>true,
            'reuse_prior_authorization'=>false,
            'reuse_prior_idempotency_key'=>false,
            'adapter_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_retry_operation_'.$code,$message,['status'=>409]);
    }
}
