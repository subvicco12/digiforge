<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Binds an approved Etsy draft operation plan to the canonical controlled
 * transport pipeline. It does not execute the operation.
 */
final class EtsyDraftOperationPipeline
{
    public function __construct(private EtsyControlledTransportOrchestrator $transport) {}

    /** @return array<string,mixed>|WP_Error */
    public function prepare(
        array $prepared,
        array $operation,
        array $tokenMetadata,
        array $draftOperation,
        array $headers=[]
    ): array|WP_Error {
        if (($draftOperation['state']??'')!=='ETSY_DRAFT_OPERATION_PLANNED'
            || ($draftOperation['network_request_permitted']??null)!==false
            || ($draftOperation['external_execution_performed']??null)!==false
            || ($draftOperation['publish_permitted']??null)!==false) {
            return self::error('operation','A network-disabled, publish-disabled Etsy draft operation is required.');
        }

        $method=(string)($draftOperation['method']??'');
        $endpoint=(string)($draftOperation['endpoint']??'');
        $payload=$draftOperation['payload']??null;
        if ($method==='' || $endpoint==='' || !is_array($payload)) {
            return self::error('plan','Draft operation must contain a bounded method, endpoint and payload.');
        }

        $operationForTransport=$operation;
        $operationForTransport['payload']=$payload;
        $transport=$this->transport->prepare(
            $prepared,
            $operationForTransport,
            $tokenMetadata,
            $method,
            $endpoint,
            $headers
        );
        if ($transport instanceof WP_Error) return $transport;

        return [
            'state'=>'ETSY_DRAFT_OPERATION_PIPELINE_PREPARED',
            'operation'=>(string)($draftOperation['operation']??''),
            'operation_id'=>(int)($transport['operation_id']??0),
            'integration_id'=>(int)($transport['integration_id']??0),
            'draft_operation'=>$draftOperation,
            'controlled_transport'=>$transport,
            'network_request_permitted'=>false,
            'external_execution_performed'=>false,
            'publish_permitted'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_draft_pipeline_'.$code,$message,['status'=>409]);
    }
}
