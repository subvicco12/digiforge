<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

/** One bounded exchange with a custody service. No HTTP client or credential material lives here. */
final class CustodyReadTransport
{
    private const REQUEST = 'DF_PRINTIFY_CUSTODY_READ_REQUEST_1';
    private const RECEIPT = 'DF_PRINTIFY_CUSTODY_READ_RECEIPT_1';
    private const TIMEOUT_MS = 2000;
    private const MAX_BYTES = 262144;

    /**
     * The exchange is injected by the trusted host, never read from WordPress settings or operator input.
     * It must enforce the deadline and byte cap while reading, and share quota across workers.
     */
    public static function read(array $scope, string $operation, array $input, \Closure $exchange, bool $fixture = false): array
    {
        $status = Status::snapshot();
        if (!$fixture && ($status['stop_all'] || $status['external_lock'] || $status['release_hold']
            || !$status['provider_execution_authorized'])) {
            return self::result('BLOCKED', 'EXECUTION_HOLD');
        }
        // No qualified operational custody, binding, or admission port is connected in this build.
        if (!$fixture) {
            return self::result('BLOCKED', 'CUSTODY_ADMISSION_UNAVAILABLE');
        }
        if (!\function_exists('wp_get_environment_type') || \wp_get_environment_type() !== 'local'
            || !\function_exists('sodium_crypto_sign_verify_detached') || !self::validScope($scope)
            || !Runtime::permitsFixtureAccount($scope['shop'], $scope['account_reference'])) {
            return self::result('DENIED', 'CUSTODY_SCOPE');
        }
        $plan = Runtime::prepareProviderRead($scope['shop'], $scope['account_reference'], $operation, $input);
        if ($plan['state'] !== 'OFFLINE_PROVIDER_READ_PREPARED') {
            return self::result('DENIED', 'READ_CONTRACT');
        }
        $typed = $plan['request'];
        if ($typed['method'] !== 'GET' || $typed['body'] !== []) {
            return self::result('DENIED', 'READ_CONTRACT');
        }
        try {
            $request = [
                'schema'=>self::REQUEST, 'evidence_class'=>'SIGNED_CUSTODY_FIXTURE',
                'operation_id'=>bin2hex(random_bytes(32)),
                'provider'=>'printify', 'shop'=>$scope['shop'], 'account_reference'=>$scope['account_reference'],
                'credential_reference'=>$scope['credential_reference'], 'source_sha'=>$scope['source_sha'],
                'source_revision'=>$scope['source_revision'], 'authorization_revision'=>$scope['authorization_revision'],
                'operation'=>$operation, 'method'=>'GET', 'endpoint'=>$typed['endpoint'],
                'required_scope'=>$typed['required_scope'], 'typed_request_sha256'=>$typed['fingerprint'],
                'timeout_ms'=>self::TIMEOUT_MS, 'max_response_bytes'=>self::MAX_BYTES,
            ];
            $digest=hash('sha256',self::encode($request));
            $start=hrtime(true);
            $response=$exchange($request,self::TIMEOUT_MS,self::MAX_BYTES);
        } catch (\Throwable) {
            return self::result('UNAVAILABLE','CUSTODY_EXCHANGE');
        }
        if (hrtime(true)-$start>=self::TIMEOUT_MS*1000000) {
            return self::result('UNAVAILABLE','CUSTODY_DEADLINE');
        }
        if (!Runtime::permitsFixtureAccount($scope['shop'],$scope['account_reference'])) {
            return self::result('DENIED','CUSTODY_SCOPE_CHANGED');
        }
        return self::receipt($response,$request,$digest,$scope,$operation,$input);
    }

    private static function receipt(mixed $response,array $request,string $digest,array $scope,string $operation,array $input): array
    {
        try {
            if (!is_array($response) || array_diff_key($response,array_flip(['record','signature','body']))!==[]
                || !is_string($response['record']??null) || strlen($response['record'])>4096
                || !self::base64($response['signature']??null,SODIUM_CRYPTO_SIGN_BYTES)
                || !is_string($response['body']??null) || strlen($response['body'])>self::MAX_BYTES
                || !sodium_crypto_sign_verify_detached(base64_decode($response['signature'],true),
                    self::RECEIPT."\n".$response['record'],base64_decode($scope['signer_key'],true))) {
                return self::result('DENIED','CUSTODY_RECEIPT');
            }
            $now=time();
            $body=$response['body'];
            $record=json_decode($response['record'],true,8,JSON_THROW_ON_ERROR);
            if (!is_array($record) || array_keys($record)!==['schema','evidence_class','request_sha256',
                'content_sha256','observed_at','expires_at','status','retry_after']
                || self::encode($record)!==$response['record'] || $record['schema']!==self::RECEIPT
                || $record['evidence_class']!==$request['evidence_class']
                || $record['request_sha256']!==$digest || $record['content_sha256']!==hash('sha256',$body)
                || !is_int($record['observed_at']) || !is_int($record['expires_at'])
                || $record['observed_at']<=$now-60 || $record['observed_at']>$now
                || $record['expires_at']<=$now || $record['expires_at']-$record['observed_at']>60
                || !is_int($record['status']) || $record['status']<100 || $record['status']>599
                || !is_int($record['retry_after']) || $record['retry_after']<0 || $record['retry_after']>60) {
                return self::result('DENIED','CUSTODY_RECEIPT');
            }
            if ($record['status']===429) {
                return self::result('RATE_LIMITED','CUSTODY_RATE_LIMIT')+['retry_after'=>$record['retry_after']];
            }
            if ($record['status']!==200 || $record['retry_after']!==0) {
                return self::result('DENIED','CUSTODY_HTTP_STATUS');
            }
            $review=Runtime::reviewProviderRead($scope['shop'],$scope['account_reference'],$operation,$input,$body);
            if ($review['state']!=='OFFLINE_PROVIDER_READ_REVIEWED') {
                return self::result('DENIED','CUSTODY_BODY');
            }
            return self::result('AUTHENTICATED_CUSTODY_FIXTURE','NONE')+[
                'evidence_class'=>$record['evidence_class'], 'freshness_verified'=>true,
                'operation_id'=>$request['operation_id'], 'request_sha256'=>$digest,
                'response_sha256'=>$record['content_sha256'], 'receipt_sha256'=>hash('sha256',$response['record']),
                'source_sha'=>$request['source_sha'], 'source_revision'=>$request['source_revision'],
                'authorization_revision'=>$request['authorization_revision'], 'shop'=>$request['shop'],
                'account_reference'=>$request['account_reference'], 'observed_at'=>$record['observed_at'],
                'expires_at'=>$record['expires_at'], 'observation'=>$review['observation'],
            ];
        } catch (\Throwable) {
            return self::result('DENIED','CUSTODY_RECEIPT');
        }
    }

    private static function validScope(array $scope): bool
    {
        $keys=['shop','account_reference','credential_reference','source_sha','source_revision','authorization_revision','signer_key'];
        if (count($scope)!==count($keys) || array_diff($keys,array_keys($scope))!==[]) {
            return false;
        }
        foreach (['account_reference','credential_reference'] as $key) {
            if (!is_string($scope[$key]) || preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$scope[$key])!==1) {
                return false;
            }
        }
        return is_string($scope['shop']) && preg_match('/^[1-9][0-9]{0,18}$/D',$scope['shop'])===1
            && is_string($scope['source_sha']) && preg_match('/^[a-f0-9]{40}$/D',$scope['source_sha'])===1
            && is_int($scope['source_revision']) && $scope['source_revision']>0
            && is_int($scope['authorization_revision']) && $scope['authorization_revision']>0
            && self::base64($scope['signer_key'], SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
    }

    private static function base64(mixed $value,int $size): bool
    {
        if (!is_string($value) || strlen($value)!==4*(int)ceil($size/3)) {
            return false;
        }
        $decoded=base64_decode($value,true);
        return is_string($decoded) && strlen($decoded)===$size && base64_encode($decoded)===$value;
    }

    private static function encode(array $data): string
    {
        return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }

    private static function result(string $state,string $reason): array
    {
        return ['state'=>$state,'reason'=>$reason,'provider_outcome_confirmed'=>false,
            'provider_execution_authorized'=>false,'retry_permitted'=>false];
    }
}
