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
    $native=Runtime::prepareNativeMutation('107','fixture-account',['action'=>$action]);
    $assertCustody($native['state']==='BLOCKED' && !$native['provider_execution_authorized']
        && $native['reason']==='NATIVE_CORE_UNAVAILABLE','Standalone fabricated native authority');
}
