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

        $operationType=strtoupper(trim((string)($operation['operation_type']??'')));
        $draftType=strtoupper(trim((string)($draftOperation['operation']??'')));
        if ($operationType==='DRAFT') $operationType='CREATE_DRAFT';
        if ($operationType!==$draftType) {
            return self::error('binding','Draft operation must exactly match the authorized ledger operation.');
        }

        $method=(string)($draftOperation['method']??'');
        $endpoint=(string)($draftOperation['endpoint']??'');
        $payload=$draftOperation['payload']??null;
        $targets=[
            'CREATE_DRAFT'=>['POST','#^/application/shops/[1-9][0-9]*/listings$#'],
            'UPDATE_DRAFT'=>['PUT','#^/application/shops/[1-9][0-9]*/listings/[1-9][0-9]*$#'],
            'UPDATE_INVENTORY'=>['PUT','#^/application/listings/[1-9][0-9]*/inventory$#'],
            'ATTACH_IMAGE'=>['POST','#^/application/shops/[1-9][0-9]*/listings/[1-9][0-9]*/images$#'],
        ];
        $target=$targets[$operationType]??null;
        if (!is_array($target) || $method!==$target[0] || !preg_match($target[1],$endpoint) || !is_array($payload)) {
            return self::error('plan','Draft mutation method, target and payload must match its authorized operation type.');
        }
        $shopReference=trim((string)($operation['shop_reference']??''));
        if (!ctype_digit($shopReference) || (int)$shopReference<1) {
            return self::error('shop_scope','Controlled Etsy mutation requires the approved numeric Etsy shop identity.');
        }
        $shopId=(int)$shopReference;
        $externalReference=trim((string)($operation['external_reference']??''));
        $listingId=ctype_digit($externalReference)?(int)$externalReference:0;
        if ($operationType==='CREATE_DRAFT') {
            $expectedEndpoint="/application/shops/{$shopId}/listings";
        } elseif ($listingId<1) {
            return self::error('listing_scope','Post-create draft mutations require the persisted Etsy listing identity.');
        } elseif ($operationType==='UPDATE_INVENTORY') {
            $expectedEndpoint="/application/listings/{$listingId}/inventory";
        } elseif ($operationType==='ATTACH_IMAGE') {
            $expectedEndpoint="/application/shops/{$shopId}/listings/{$listingId}/images";
        } else {
            $expectedEndpoint="/application/shops/{$shopId}/listings/{$listingId}";
        }
        if (!hash_equals($expectedEndpoint,$endpoint)) {
            return self::error('resource_scope','Draft mutation target does not match the approved persisted Etsy resource identity.');
        }

        $preparedPayload=(array)($prepared['payload']??[]);
        $draftFingerprint=EtsyRequestFingerprint::fromPayload($payload);
        $preparedFingerprint=EtsyRequestFingerprint::fromPayload($preparedPayload);
        if ($draftFingerprint instanceof WP_Error) return $draftFingerprint;
        if ($preparedFingerprint instanceof WP_Error) return $preparedFingerprint;
        if (!hash_equals($preparedFingerprint,$draftFingerprint)) {
            return self::error('payload','Draft payload must exactly match the already-authorized prepared payload.');
        }

        $operationForTransport=$operation;
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
