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

        // Re-check the interlock immediately before secret retrieval/network use.
        $fresh=EtsyLiveTransportInterlock::authorize($prepared);
        if ($fresh instanceof WP_Error) return $fresh;

        $credential=(new EtsyScopedCredentialRetriever())->retrieve($transport['credential_scope']);
        if ($credential instanceof WP_Error) return $credential;

        $method=(string)($request['method']??'');
        $endpoint=(string)($request['endpoint']??'');
        $headers=is_array($request['headers']??null)?$request['headers']:[];
        $payload=is_array($request['payload']??null)?$request['payload']:[];
        $sender=$this->sender;

        $attempt=$credential->consume($integrationId,$operationId,static function(string $token) use ($sender,$method,$endpoint,$headers,$payload,$operationId): array {
            $headers['Authorization']='Bearer '.$token;
            $args=[
                'method'=>$method,
                'headers'=>$headers,
                'timeout'=>15,
                'redirection'=>0,
                'sslverify'=>true,
            ];
            if ($payload!==[] && $method!=='GET') $args['body']=wp_json_encode($payload);
            $attemptId=wp_generate_uuid4();
            $attemptedAt=gmdate('c');
            $response=$sender('https://openapi.etsy.com/v3'.$endpoint,$args);
            $token='';
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
        if (($classified['state']??'')==='RESPONSE_ACCEPTED') {
            $body=(string)wp_remote_retrieve_body($response);
            $operationType=strtoupper(trim((string)($prepared['operation_type']??($prepared['invocation_plan']['operation_type']??''))));
            $existingReference=trim((string)($prepared['external_reference']??($prepared['invocation_plan']['external_reference']??'')));
            $parsed=EtsyAcceptedResponseParser::parse($operationType,$body,$existingReference);
            $body='';
            if ($parsed instanceof WP_Error) return $parsed;
            $externalReference=(string)$parsed['external_reference'];
        }

        return [
            'state'=>'ETSY_HTTP_ATTEMPT_COMPLETED',
            'operation_id'=>$operationId,
            'attempt'=>$attempt,
            'http_outcome'=>$classified,
            'rate_limit'=>$rateLimit,
            'external_reference'=>$externalReference,
            'response_body_returned'=>false,
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
