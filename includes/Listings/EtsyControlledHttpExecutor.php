<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Guarded Etsy HTTP attempt boundary.
 *
 * The injected sender makes the boundary testable without network traffic.
 * Production callers may inject a WordPress HTTP sender only after the live
 * interlock has authorized execution. Credential material exists only inside
 * the single-use envelope callback and is never returned.
 */
final class EtsyControlledHttpExecutor
{
    /** @var callable */
    private $sender;

    public function __construct(?callable $sender=null)
    {
        $this->sender=$sender ?? static function(string $url,array $args): mixed {
            return wp_remote_request($url,$args);
        };
    }

    /** @return array<string,mixed>|WP_Error */
    public function execute(array $prepared,array $authorized): array|WP_Error
    {
        if (($authorized['state']??'')!=='ETSY_LIVE_TRANSPORT_AUTHORIZED'
            || ($authorized['network_request_permitted']??null)!==true
            || ($authorized['network_request_attempted']??null)!==false) {
            return self::error('authorization','Live Etsy transport authorization is required.');
        }

        $operationId=(int)($prepared['operation_id']??0);
        $integrationId=(int)($prepared['integration_id']??0);
        if ($operationId<1 || $integrationId<1
            || $operationId!==(int)($authorized['operation_id']??0)
            || $integrationId!==(int)($authorized['integration_id']??0)) {
            return self::error('identity','Prepared and authorized Etsy transport identities must match.');
        }

        $transport=$prepared['transport']??null;
        $request=$prepared['request_plan']??null;
        if (!is_array($transport) || !is_array($request)
            || ($transport['state']??'')!=='ETSY_CREDENTIAL_AWARE_TRANSPORT_PREPARED'
            || ($transport['credential_retrieval_deferred']??null)!==true
            || !is_array($transport['credential_scope']??null)) {
            return self::error('prepared','A deferred-credential Etsy transport is required.');
        }

        $method=(string)($request['method']??'');
        $endpoint=(string)($request['endpoint']??'');
        $headers=is_array($request['headers']??null)?$request['headers']:[];
        $payload=is_array($request['payload']??null)?$request['payload']:[];
        $multipart=is_array($request['multipart']??null)?$request['multipart']:null;
        $multipartBytes=null;
        if ($multipart!==null) {
            $preparedState=(string)($multipart['state']??'');
            $asset=(string)($multipart['file_path']??'');
            $field=(string)($multipart['field']??'');
            $filename=(string)($multipart['filename']??'');
            $mime=(string)($multipart['mime_type']??'');
            $expectedSize=(int)($multipart['size']??0);
            $expectedHash=strtolower((string)($multipart['sha256']??''));
            $rank=(int)($multipart['rank']??0);
            $imagePlan=$preparedState==='ETSY_MULTIPART_IMAGE_PREPARED'&&$field==='image'&&$rank>=1&&$rank<=10;
            $filePlan=$preparedState==='ETSY_MULTIPART_FILE_PREPARED'&&$field==='file'&&$rank>=1;
            if((!$imagePlan&&!$filePlan)||$asset===''||$filename==='') return self::error('multipart','A prepared Etsy multipart image or digital-file plan is required.');
            if(!is_file($asset)||!is_readable($asset)) return self::error('multipart_asset','Prepared image asset is unavailable before external execution.');
            $size=filesize($asset);
            $actualMime=function_exists('mime_content_type')?(string)mime_content_type($asset):'';
            $multipartBytes=file_get_contents($asset);
            if(!is_string($multipartBytes)||$multipartBytes==='') return self::error('multipart_asset','Prepared image asset could not be read before credential retrieval.');
            $actualHash=hash('sha256',$multipartBytes);
            if($size===false||$expectedSize<1||$expectedSize>10485760||$size!==$expectedSize||strlen($multipartBytes)!==$expectedSize||$actualMime!==$mime||!hash_equals($expectedHash,strtolower($actualHash))) { $multipartBytes=''; return self::error('multipart_integrity','Prepared image metadata does not match the exact bytes selected for transmission.'); }
        }
        // Re-check the live interlock only after local request and multipart validation.
        $fresh=EtsyLiveTransportInterlock::authorize($prepared);
        if ($fresh instanceof WP_Error) return $fresh;

        $credential=(new EtsyScopedCredentialRetriever())->retrieve($transport['credential_scope']);
        if ($credential instanceof WP_Error) return $credential;

        $sender=$this->sender;

        $attempt=$credential->consume($integrationId,$operationId,static function(string $token,string $apiKey) use ($sender,$method,$endpoint,$headers,$payload,$multipart,$multipartBytes,$operationId,$filePlan): array {
            $headers['Authorization']='Bearer '.$token;
            $headers['x-api-key']=$apiKey;
            $args=[
                'method'=>$method,
                'headers'=>$headers,
                'timeout'=>15,
                'redirection'=>0,
                'sslverify'=>true,
            ];
            if ($multipart!==null) {
                $asset=(string)$multipart['file_path'];
                $field=(string)$multipart['field'];
                $mime=(string)$multipart['mime_type'];
                $filename=(string)$multipart['filename'];
                $rank=(int)$multipart['rank'];
                $bytes=is_string($multipartBytes)?$multipartBytes:'';
                if($bytes==='') return ['operation_id'=>$operationId,'attempt_id'=>'','attempted_at'=>gmdate('c'),'adapter_invoked'=>false,'external_request_attempted'=>false,'response'=>new WP_Error('digiforge_etsy_multipart_asset','Bound multipart bytes are unavailable.')];
                $boundary='DigiForgeEtsy'.str_replace('-','',wp_generate_uuid4());
                $built=EtsyMultipartBodyBuilder::build($multipart,$bytes,$boundary,$filePlan);
                if($built instanceof WP_Error) return ['operation_id'=>$operationId,'attempt_id'=>'','attempted_at'=>gmdate('c'),'adapter_invoked'=>false,'external_request_attempted'=>false,'response'=>$built];
                unset($headers['content-type'],$headers['Content-Type'],$headers['content-length'],$headers['Content-Length']);
                $headers['Content-Type']=$built['content_type'];
                $headers['Content-Length']=(string)$built['content_length'];
                $args['headers']=$headers;
                $args['body']=$built['body'];
                $bytes='';
            } elseif ($payload!==[] && $method!=='GET') {
                $contentType=strtolower((string)($headers['Content-Type']??$headers['content-type']??''));
                $args['body']=str_contains($contentType,'application/x-www-form-urlencoded')?http_build_query($payload,'','&',PHP_QUERY_RFC3986):wp_json_encode($payload);
            }
            $attemptId=wp_generate_uuid4();
            $attemptedAt=gmdate('c');
            $response=$sender('https://openapi.etsy.com/v3'.$endpoint,$args);
            $token='';$apiKey='';
            return [
                'operation_id'=>$operationId,
                'attempt_id'=>$attemptId,
                'attempted_at'=>$attemptedAt,
                'adapter_invoked'=>true,
                'external_request_attempted'=>true,
                'response'=>$response,
            ];
        });
        if ($attempt instanceof WP_Error) return $attempt;

        $response=$attempt['response']??null;
        unset($attempt['response']);
        $sanitized=is_wp_error($response)
            ? ['transport_state'=>'NO_RESPONSE']
            : ['transport_state'=>'RESPONSE_RECEIVED','http_status'=>(int)wp_remote_retrieve_response_code($response)];
        $classified=EtsyHttpOutcome::classify($sanitized);
        if ($classified instanceof WP_Error) return $classified;
        $rateLimit=EtsyRateLimitMetadata::fromHeaders(is_wp_error($response)?[]:wp_remote_retrieve_headers($response));
        if ($rateLimit instanceof WP_Error) return $rateLimit;

        $externalReference='';
        $externalAssetReference='';
        $reconciliationResponse=null;
        $providerErrorEvidence=[];
        if (($classified['state']??'')==='RESPONSE_ACCEPTED') {
            $body=(string)wp_remote_retrieve_body($response);
            if($method==='GET') {
                // Bounded read-only evidence is returned only to the reconciliation
                // executor; mutation callers still receive no response body.
                if(strlen($body)>1048576) {
                    $classified=['state'=>'UNKNOWN','failure_category'=>'response_processing','failure_code'=>'accepted_response_too_large','retry_candidate'=>false,'reconciliation_required'=>true,'automatic_retry_permitted'=>false];
                } else {
                    $reconciliationResponse=['status'=>(int)wp_remote_retrieve_response_code($response),'body'=>$body];
                }
            }
            if($method!=='GET') {
                $operationType=strtoupper(trim((string)($prepared['operation_type']??($prepared['invocation_plan']['operation_type']??''))));
                $existingReference=trim((string)($prepared['external_reference']??($prepared['invocation_plan']['external_reference']??'')));
                $parsed=EtsyAcceptedResponseParser::parse($operationType,$body,$existingReference);
                $body='';
                if ($parsed instanceof WP_Error) {
                    $classified=['state'=>'UNKNOWN','failure_category'=>'response_processing','failure_code'=>'accepted_response_unparseable','retry_candidate'=>false,'reconciliation_required'=>true,'automatic_retry_permitted'=>false];
                } else {
                    $externalReference=(string)$parsed['external_reference'];
                    $externalAssetReference=(string)($parsed['external_asset_reference']??'');
                }
            } else {
                // GET reconciliation bodies are endpoint-specific evidence. They
                // must be normalized by EtsyReconciliationLookupResponse rather
                // than by the mutation response parser.
                $body='';
            }
        }

        if (($classified['state']??'')==='CONFIRMED_FAILURE' && !is_wp_error($response)) {
            $providerErrorEvidence=EtsyProviderErrorEvidence::extract((string)wp_remote_retrieve_body($response));
        }

        return [
            'state'=>'ETSY_HTTP_ATTEMPT_COMPLETED',
            'operation_id'=>$operationId,
            'attempt'=>$attempt,
            'http_outcome'=>$classified,
            'rate_limit'=>$rateLimit,
            'external_reference'=>$externalReference,
            'external_asset_reference'=>$externalAssetReference,
            'response_body_returned'=>false,
            'reconciliation_response'=>$reconciliationResponse,
            'provider_error_evidence'=>$providerErrorEvidence,
            'credential_material_exposed'=>false,
            'authorization_header_returned'=>false,
            'network_request_attempted'=>true,
            'external_execution_performed'=>true,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_http_executor_'.$code,$message,['status'=>409]);
    }
}
