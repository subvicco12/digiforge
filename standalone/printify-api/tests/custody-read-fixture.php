<?php
declare(strict_types=1);

use DigiForgePrintifyApi\Runtime;
use DigiForgePrintifyApi\CustodyReadTransport;

$assertCustody = static function (bool $valid, string $message): void {
    if (!$valid) {
        throw new RuntimeException($message);
    }
};
$attempts = 0;
$keypair = sodium_crypto_sign_keypair();
$pin = base64_encode(sodium_crypto_sign_publickey($keypair));
$scope = ['shop'=>'107','account_reference'=>'fixture-account','credential_reference'=>'fixture-custody-read',
    'source_sha'=>str_repeat('a',40),'source_revision'=>1,'authorization_revision'=>3,'signer_key'=>$pin];
$body = '{"id":"order-1","shop_id":107,"status":"fulfilled","line_items":[],"address_to":{"email":"private@example.invalid"}}';
$case = 'valid';
$priorReceipt=null;
$sign = static function (array $request, string $body, int $status, int $retry, array $changes = []) use ($keypair): array {
    $now = time();
    $record = array_replace([
        'schema'=>'DF_PRINTIFY_CUSTODY_READ_RECEIPT_1','evidence_class'=>'SIGNED_CUSTODY_FIXTURE',
        'request_sha256'=>hash('sha256', json_encode($request, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),
        'content_sha256'=>hash('sha256',$body),'observed_at'=>$now,'expires_at'=>$now+30,
        'status'=>$status,'retry_after'=>$retry,
    ],$changes);
    $json=json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    return ['record'=>$json,'signature'=>base64_encode(sodium_crypto_sign_detached(
        "DF_PRINTIFY_CUSTODY_READ_RECEIPT_1\n".$json,sodium_crypto_sign_secretkey($keypair))),'body'=>$body];
};
$exchange = static function (array $request, int $timeout, int $maximum) use (&$attempts,&$case,&$priorReceipt,$body,$sign,$assertCustody): array {
    ++$attempts;
    $assertCustody($request['method']==='GET' && $request['endpoint']==='/v1/shops/107/orders/order-1.json'
        && $request['credential_reference']==='fixture-custody-read' && $timeout===2000 && $maximum===262144,
        'Unexpected custody request or budget');
    return match ($case) {
        'timeout'=>throw new RuntimeException('Synthetic timeout'),
        'substituted'=>$sign(array_replace($request,['account_reference'=>'other']),$body,200,0),
        'cross_shop'=>$sign($request,str_replace('107','108',$body),200,0),
        'stale'=>$sign($request,$body,200,0,['observed_at'=>time()-60,'expires_at'=>time()-1]),
        'synthetic_promote'=>$sign($request,$body,200,0,['evidence_class'=>'SIGNED_PROVIDER_READ']),
        'oversize'=>$sign($request,str_repeat('a',262145),200,0),
        'tamper'=>array_replace($sign($request,$body,200,0),['body'=>'{}']),
        'limited'=>$sign($request,'',429,25),
        'redirect'=>$sign($request,'',302,0),
        'malformed'=>$sign($request,'{bad',200,0),
        'replay'=>$priorReceipt,
        'revoked'=>(static function() use ($request,$body,$sign):array {
            $GLOBALS['fixture_caps']=[];
            return $sign($request,$body,200,0);
        })(),
        default=>$priorReceipt=$sign($request,$body,200,0),
    };
};
$input = ['order_id'=>'order-1'];
$assertCustody(Runtime::readFromCustody($scope,'ORDER_READ',$input,$exchange)['state']==='BLOCKED' && $attempts===0,
    'Held operational route reached custody');
$assertCustody(Runtime::readFromCustody($scope,'PRODUCT_PUBLISH',[],$exchange,true)['state']==='DENIED' && $attempts===0,
    'Mutation reached custody');
$assertCustody(Runtime::readFromCustody(array_replace($scope,['shop'=>'108']),'ORDER_READ',$input,$exchange,true)['state']==='DENIED'
    && $attempts===0,'Cross-shop request reached custody');
$read=Runtime::readFromCustody($scope,'ORDER_READ',$input,$exchange,true);
$assertCustody($read['state']==='AUTHENTICATED_CUSTODY_FIXTURE' && $read['observation']['id']==='order-1'
    && !str_contains(json_encode($read),'private@example.invalid') && !$read['provider_outcome_confirmed']
    && !$read['provider_execution_authorized'],'Signed fixture projection was missing or overclaimed');
foreach (['timeout','substituted','cross_shop','stale','synthetic_promote','oversize','tamper','limited','redirect','malformed','replay','revoked'] as $case) {
    $before=$attempts;
    $decision=Runtime::readFromCustody($scope,'ORDER_READ',$input,$exchange,true);
    $expected=match($case) {'timeout'=>'UNAVAILABLE','limited'=>'RATE_LIMITED',default=>'DENIED'};
    $assertCustody($decision['state']===$expected && $attempts===$before+1 && !$decision['retry_permitted']
        && ($case!=='limited'||$decision['retry_after']===25),'Unexpected decision or retry for '.$case);
}

$GLOBALS['fixture_caps']=['manage_options','digiforge_printify_review_shop_107'];
foreach (['REGISTER','CLAIM','RECEIPT_READ'] as $action) {
    $native=Runtime::prepareNativeMutation('107','fixture-account',[
        'action'=>$action,'binding'=>['provider_shop_id'=>'107','environment'=>'test'],
    ]);
    $assertCustody($native['state']==='BLOCKED' && !$native['provider_execution_authorized']
        && $native['reason']==='NATIVE_CORE_UNAVAILABLE','Standalone fabricated native authority');
}

$readiness=Runtime::integrationReadiness('107','fixture-account');
$assertCustody($readiness['provider_read']['offline']==='OFFLINE_VERIFIED'
    && $readiness['provider_read']['hosted']==='BLOCKED_EXTERNAL'
    && $readiness['provider_read']['provider']==='BLOCKED_EXTERNAL'
    && $readiness['provider_read']['dispatch']==='DISABLED_BY_POLICY'
    && $readiness['native_mutation']['core_build']==='NATIVE_CORE_UNAVAILABLE'
    && $readiness['native_mutation']['admission']==='BLOCKED_EXTERNAL','Operational capability was overclaimed');
$assertCustody(Runtime::integrationReadiness('108','fixture-account')===[],'Cross-shop readiness disclosure');

$prepared=CustodyReadTransport::prepareHostedFixture($scope);
$assertCustody($prepared['state']==='ASYNC_FIXTURE_PREPARED' && !$prepared['provider_execution_authorized'],
    'Async preparation unexpectedly authorizes dispatch');
$request=json_decode($prepared['wire'],true,12,JSON_THROW_ON_ERROR);
$export=json_encode(['instance_id'=>$prepared['instance_id'],'status'=>'complete',
    'output'=>$sign($request,$body,200,0)],JSON_THROW_ON_ERROR);
$review=static fn(string $wire,?string $digest=null):array =>
    CustodyReadTransport::reviewHostedFixture($scope,$prepared['operation_id'],$digest??$prepared['request_sha256'],$wire);
$read=$review($export);
$assertCustody($read['state']==='ASYNC_FIXTURE_RECEIPT_VERIFIED' && $read['observation']['id']==='order-1'
    && !$read['synchronous_exchange_verified'] && !$read['provider_outcome_confirmed'],'Async readback overclaimed or invalid');
$assertCustody($review(str_replace($prepared['instance_id'],'wrong',$export))['state']==='DENIED','Wrong workflow instance admitted');
$assertCustody($review($export,str_repeat('0',64))['state']==='DENIED','Wrong approved digest admitted');
$assertCustody($review(json_encode(['instance_id'=>$prepared['instance_id'],'status'=>'errored','output'=>null]))['state']==='UNKNOWN',
    'Failed hosted run loses uncertainty');
$assertCustody($review(json_encode(['instance_id'=>$prepared['instance_id'],'status'=>'complete','output'=>null]))['state']==='UNKNOWN',
    'Missing hosted output loses uncertainty');
$expired=$sign($request,$body,200,0,['observed_at'=>time()-40,'expires_at'=>time()-1]);
$assertCustody($review(json_encode(['instance_id'=>$prepared['instance_id'],'status'=>'complete','output'=>$expired]))['state']==='DENIED',
    'Expired hosted receipt admitted');

// Exercise the actual Worker and Workflow classes through the offline platform stub.
$fixturePair=sodium_crypto_sign_seed_keypair(hash('sha256','public-test-only-custody-mock-v1',true));
$fixtureScope=array_replace($scope,['signer_key'=>base64_encode(sodium_crypto_sign_publickey($fixturePair))]);
$intent=CustodyReadTransport::prepareHostedFixture($fixtureScope);
$testDir=dirname(__DIR__,3).'/contracts/cloudflare-dispatcher/test/';
$process=proc_open(['node','--import',$testDir.'stage-a-platform-register.mjs',$testDir.'custody-first-create-process.mjs'],
    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if (!is_resource($process)) throw new RuntimeException('Cannot start platform fixture');
try {
    fwrite($pipes[0],$intent['wire']."\n");
    fclose($pipes[0]);
    stream_set_timeout($pipes[1],5);
    $output=stream_get_contents($pipes[1],270337);
    $result=CustodyReadTransport::reviewHostedFixture($fixtureScope,$intent['operation_id'],$intent['request_sha256'],$output);
    $assertCustody($result['state']==='ASYNC_FIXTURE_RECEIPT_VERIFIED','Offline Workflow-to-PHP receipt denied');
} finally {
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_terminate($process);
    proc_close($process);
}
