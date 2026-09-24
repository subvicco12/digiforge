<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Composes the certified Etsy draft pipeline, final live interlock, guarded
 * executor, and persistent attempt lifecycle. This adds no publish path and
 * cannot bypass the existing production safety controls.
 */
final class EtsyControlledDraftExecutionCoordinator
{
    public function __construct(
        private EtsyDraftOperationPipeline $pipeline,
        private EtsyOperationRepository $operations,
        private ?EtsyControlledHttpExecutor $executor=null
    ) {}

    /** @return array<string,mixed>|WP_Error */
    public function execute(
        array $prepared,
        array $operation,
        array $tokenMetadata,
        array $draftOperation,
        array $headers=[],
        ?array $multipart=null
    ): array|WP_Error {
        $pipeline=$this->pipeline->prepare($prepared,$operation,$tokenMetadata,$draftOperation,$headers);
        if($pipeline instanceof WP_Error) return $pipeline;

        $transport=$pipeline['controlled_transport']??null;
        if(!is_array($transport)||($transport['state']??'')!=='ETSY_CONTROLLED_TRANSPORT_PREPARED') {
            return self::error('transport','A controlled Etsy transport is required.');
        }

        if($multipart!==null) {
            if(strtoupper((string)($draftOperation['operation']??''))!=='ATTACH_IMAGE') {
                return self::error('multipart_operation','Multipart assets are permitted only for ATTACH_IMAGE.');
            }
            $transport=EtsyMultipartTransportBinder::bind($transport,$multipart);
            if($transport instanceof WP_Error) return $transport;
        } elseif(strtoupper((string)($draftOperation['operation']??''))==='ATTACH_IMAGE'
            && array_key_exists('image_sha256',(array)($draftOperation['payload']??[]))) {
            return self::error('multipart_required','Binary ATTACH_IMAGE requires the prepared multipart asset.');
        }

        $operationId=(int)($operation['id']??0);
        if($operationId<1 || !$this->operations->acquireExecutionLock($operationId)) return self::error('claimed','Etsy operation is already being executed.');
        try {
            $current=$this->operations->find($operationId);
            if(!is_array($current)||(string)($current['state']??'')!==EtsyOperationLifecycle::NOT_SENT) return self::error('stale','Only the current persisted NOT_SENT operation may execute.');

            $transport['operation_type']=(string)($operation['operation_type']??'');
            $transport['external_reference']=(string)($operation['external_reference']??'');

            // Persist the already-authorized canonical payload before any network
            // attempt can become ambiguous. This is local-only and fingerprint-bound.
            $evidence=$this->operations->recordReconciliationEvidence($operationId,(array)($draftOperation['payload']??[]));
            if($evidence instanceof WP_Error) return $evidence;

            $authorized=EtsyLiveTransportInterlock::authorize($transport);
            if($authorized instanceof WP_Error) return $authorized;

            $executor=$this->executor??new EtsyControlledHttpExecutor();
            $execution=$executor->execute($transport,$authorized);
            if($execution instanceof WP_Error) return $execution;

        $invocation=$transport['request_plan']['invocation_plan']??($transport['transport']['request_plan']['invocation_plan']??null);
        if(!is_array($invocation)) {
            // Rebuild the lifecycle evidence from the same consumed handoff without
            // changing state or acquiring a second permit.
            $handoff=EtsyConsumedPermitHandoff::validate($prepared);
            if($handoff instanceof WP_Error) return $handoff;
            $invocation=EtsyAdapterInvocationPlan::build($handoff,$operation);
            if($invocation instanceof WP_Error) return $invocation;
        }

            $lifecycle=(new EtsyAttemptLifecycleService($this->operations))->record($invocation,$execution);
            if($lifecycle instanceof WP_Error) return $lifecycle;

            return [
            'state'=>'ETSY_CONTROLLED_DRAFT_EXECUTION_RECORDED',
            'operation_id'=>(int)($lifecycle['operation_id']??0),
            'operation'=>(string)($draftOperation['operation']??''),
            'lifecycle'=>$lifecycle,
            'reconciliation_required'=>(bool)($lifecycle['reconciliation_required']??false),
            'automatic_retry_permitted'=>false,
            'publish_permitted'=>false,
                'external_execution_performed'=>true,
            ];
        } finally {
            $this->operations->releaseExecutionLock($operationId);
        }
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_controlled_draft_execution_'.$code,$message,['status'=>409]);
    }
}
