<?php
declare(strict_types=1);

// Disposable subprocess. Stdio is the only request channel. No network or credential access.
$request = fgets(STDIN,8192);
$plan = is_string($request) ? json_decode($request,true,8,JSON_THROW_ON_ERROR) : null;
if (!is_array($plan) || $plan['schema']!=='DF_PRINTIFY_CUSTODY_READ_REQUEST_1'
    || $plan['evidence_class']!=='SIGNED_CUSTODY_FIXTURE' || $plan['method']!=='GET'
    || $plan['endpoint']!=='/v1/shops/107/orders/order-1.json' || $plan['required_scope']!=='orders.read'
    || $plan['shop']!=='107' || $plan['account_reference']!=='fixture-account'
    || $plan['credential_reference']!=='fixture-custody-read') {
    exit(2);
}
$now=time();
$body='{"id":"order-1","shop_id":107,"status":"fulfilled","line_items":[]}';
$record=json_encode([
    'schema'=>'DF_PRINTIFY_CUSTODY_READ_RECEIPT_1','evidence_class'=>'SIGNED_CUSTODY_FIXTURE',
    'request_sha256'=>hash('sha256',rtrim($request,"\n")),
    'content_sha256'=>hash('sha256',$body),'observed_at'=>$now,'expires_at'=>$now+30,
    'status'=>200,'retry_after'=>0,
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$keypair=sodium_crypto_sign_seed_keypair(hash('sha256','public-test-only-custody-mock-v1',true));
echo json_encode(['record'=>$record,'signature'=>base64_encode(sodium_crypto_sign_detached(
    "DF_PRINTIFY_CUSTODY_READ_RECEIPT_1\n".$record,sodium_crypto_sign_secretkey($keypair))),'body'=>$body],JSON_THROW_ON_ERROR);
