<?php
declare(strict_types=1);
// Read actual committed rows under the operator's browser-issued sessions.
// No test-only HTTP endpoint, replacement producer or authority receipt.
$dir=getenv('DF_LOCAL_BROWSER_DIR');$repo=getenv('DF_LOCAL_BROWSER_REPO');
if(PHP_SAPI!=='cli'||getenv('DF_LOCAL_BROWSER_DISPOSABLE')!=='1'||!is_string($dir)||!is_file($dir.'/disposable.marker'))throw new RuntimeException('Explicit disposable CLI fixture required.');
require $dir.'/test-config.php';
if(!preg_match('/^(?:127\.0\.0\.1|localhost)(?::[0-9]+)?$/D',DB_HOST)||!str_contains(DB_NAME,'tests')||!preg_match('/^b[a-f0-9]{3}_$/D',$table_prefix))throw new RuntimeException('Isolated loopback fixture required.');
$cookies=json_decode(file_get_contents($dir.'/operator-cookies.json'),true,32,JSON_THROW_ON_ERROR);
foreach($cookies as $cookie)$_COOKIE[$cookie['name']]=rawurldecode($cookie['value']);
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTP_HOST']=(string)parse_url(WP_HOME,PHP_URL_HOST).':'.parse_url(WP_HOME,PHP_URL_PORT);
require $dir.'/core/wp-load.php';
$fixture=json_decode(file_get_contents($dir.'/journeys.json'),true,32,JSON_THROW_ON_ERROR);$f=$fixture['journeys'][0];
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$checked=static function($v){if(is_wp_error($v))throw new RuntimeException($v->get_error_code());return $v;};
$assert(get_current_user_id()===$fixture['operator']['id'],'The real browser operator session is required.');
$checked(\DigiForge\Connector\Printify\NativeAuthorityAdapter::session(get_current_user_id()));
$assert(\DigiForge\PortalAccess\Session::valid($checked(\DigiForge\PortalAccess\Store::read(get_current_user_id()))),'Actual portal session must remain current.');
global $wpdb;
$order=(int)$f['order'];$intentId=(int)($argv[1]??0);$expected=$argv[2]??'POSITIVE';
$plans=\DigiForge\Database\Tables::fulfillment_plans();$intents=\DigiForge\Database\Tables::fulfillment_intents();
$intent=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$intents.' WHERE id=%d AND order_id=%d',$intentId,$order),ARRAY_A);
$assert(is_array($intent)&&$intent['state']==='BLOCKED','Exact intent must stay BLOCKED.');
$plan=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$plans.' WHERE id=%d',(int)$intent['fulfillment_plan_id']),ARRAY_A);
$assert(is_array($plan)&&$plan['state']==='APPROVED','Actual human-reviewed local plan is required.');
$before=hash('sha256',wp_json_encode([$plan,$intent]));
$held=(new \DigiForge\Orders\HeldFulfillmentPreparation())->prepare('personalized_pod',$order,$intentId);
if($expected==='POSITIVE'){
    $held=$checked($held);$payload=json_decode($plan['canonical_payload'],true,32,JSON_THROW_ON_ERROR);
    $photo=$checked((new \DigiForge\POD\CustomerPhotoSelection())->forOrder('personalized_pod',$order,(int)$f['line']));
    $assert($payload['personalization'][0]['selection_sha256']===$photo['sha256'],'Plan must bind actual current canonical selection.');
    // Stored canonical JSON sorts associative keys; preserve that actual contract.
    $assert(\DigiForge\Orders\Validator::canonicalJson($held['preparation']['route']['payload']['personalization'])===\DigiForge\Orders\Validator::canonicalJson($payload['personalization']),'Held payload must bind every immutable plan personalization field exactly.');
    $assert($held['external_execution_authorized']===false&&$held['external_execution_performed']===false&&$held['preparation']['adapter_invoked']===false&&$held['release_hold']===true,'Actual held preparation must deny execution.');
    $status=$checked((new \DigiForge\POD\CustomerPhotoSelection())->status($f['session']));$binding=$status['render'];
    $render=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.\DigiForge\Database\Tables::pod_render_evidence().' WHERE id=%d',(int)$binding['render_id']),ARRAY_A);
    $assert(is_array($render)&&(int)$render['order_id']===$order&&$render['review_status']==='APPROVED'&&(int)$render['reviewed_by']!==$f['customer'],'Exact independent current render review is required.');
    $certificate=$checked((new \DigiForge\POD\RenderArtifactVerifier())->assertCertified($render));
    $output=$certificate['outputs'][$binding['panel']]??$certificate['output'];$preview=$certificate['buyer_previews'][$binding['panel']]??$certificate['buyer_preview'];
    $assert($output['sha256']===$preview['sha256']&&$output['sha256']===($argv[4]??''),'Persisted production artifact and preview must equal the exact browser-reviewed PNG.');
    $job=$checked((new \DigiForge\Queue\Shop2HeldJobRecovery())->inspect('personalized_pod',$order,$intentId,(int)($argv[3]??0)));
    $assert($job['job']['state']==='BLOCKED'&&(int)$job['job']['attempts']===0&&$job['job']['locked_at']===null&&$job['job']['lease_expires_at']===null&&$job['external_execution_authorized']===false,'Actual persisted job must stay unclaimed and BLOCKED.');
}else{
    $assert(in_array($expected,['STALE','REVOKED'],true)&&is_wp_error($held),'Changed or revoked evidence must deny held preparation.');
}
$assert(is_wp_error((new \DigiForge\Orders\HeldFulfillmentPreparation())->prepare('standard_pod',$order,$intentId)),'Cross-shop held access must fail closed.');
$after=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$plans.' WHERE id=%d',(int)$plan['id']),ARRAY_A);
$afterIntent=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$intents.' WHERE id=%d',$intentId),ARRAY_A);
$assert($before===hash('sha256',wp_json_encode([$after,$afterIntent])),'Inspection and denials must preserve immutable plan and BLOCKED intent.');
$assert(\DigiForge\Core\Settings::safety_locked()&&\DigiForge\Core\Settings::get('stop_all',true)&&!\DigiForge\Core\Settings::get('automation_armed',false),'All production holds must remain active.');
foreach([\DigiForge\Database\Tables::pod_execution_receipts(),\DigiForge\Database\Tables::listings(),\DigiForge\Database\Tables::etsy_intents()]as $table){$count=$wpdb->get_var('SELECT COUNT(*) FROM '.$table);$assert($wpdb->last_error===''&&$count!==null&&(int)$count===0,'No execution receipt, listing or Etsy intent may be created.');}
echo wp_json_encode(['expectation'=>$expected,'actual_result'=>is_wp_error($held)?$held->get_error_code():$held['state'],'plan_payload_sha256'=>$plan['payload_hash'],'immutable_plan_intent_sha256'=>$before,'persisted_artifact_sha256'=>$output['sha256']??null,'persisted_job'=>isset($job)?array_intersect_key($job['job'],array_flip(['state','attempts','locked_at','lease_expires_at'])):null,'cross_shop_denied'=>true,'browser_session_verified'=>true,'native_order_version'=>null,'external_execution_authorized'=>false])."\n";
