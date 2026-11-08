<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Listings\Validator;
use DigiForge\PortalAccess\Owner;
use DigiForge\Security\{ShopAccess,ReviewedLocalEvidence,Logger};
use WP_Error;

/** Explicit human-reviewed lifecycle dispatch; no scheduled or implicit provider operations. */
final class PrintifyLifecycleWorkflow
{
 public const CAPABILITY='printify.lifecycle.prepare';
 private static function error(string $reason):WP_Error{return new WP_Error('printify_lifecycle_'.$reason,'Printify lifecycle admission blocked: '.$reason.'.',['status'=>409,'retry_permitted'=>false]);}
 private static function access(string $shop,string $op):bool{
  if(!in_array($shop,['personalized_pod','standard_pod'],true))return false;
  $caps=['manage_digiforge_connections'];if(in_array($op,['SHIPPING_QUOTE','ORDER_CANCEL','LINE_ITEM_CANCEL'],true))$caps[]='manage_digiforge_orders';
  foreach($caps as $cap)if(!current_user_can($cap)||ShopAccess::authorize($shop,$cap)!==true)return false;return true;
 }
 private function save(string $kind,array $record):array|WP_Error{
  try{$record['seal']=hash_hmac('sha256',Validator::canonicalJson($record),wp_salt('auth'));$raw=Validator::canonicalJson($record);if(strlen($raw)>524288)return self::error('size');$hash=hash('sha256',$raw);$name='digiforge_pfy_lifecycle_'.$kind.'_'.$hash;$cipher=CredentialVault::encrypt($raw,'printify-lifecycle:'.$kind.':'.$hash);
   if(!add_option($name,$cipher,'',false)){$existing=$this->load($kind,$hash);if(is_wp_error($existing))return $existing;}
   return ['hash'=>$hash,'record'=>$record];
  }catch(\Throwable){return self::error('persistence');}
 }
 private function load(string $kind,string $hash):array|WP_Error{
  if(!preg_match('/^[a-f0-9]{64}$/D',$hash))return self::error('identity');global $wpdb;$wpdb->last_error='';$cipher=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s','digiforge_pfy_lifecycle_'.$kind.'_'.$hash));
  if($wpdb->last_error!==''||!is_string($cipher)||strlen($cipher)>1500000)return self::error('record');
  try{$raw=CredentialVault::decrypt($cipher,'printify-lifecycle:'.$kind.':'.$hash);if(!hash_equals($hash,hash('sha256',$raw)))return self::error('integrity');$r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($r)||!is_string($r['seal']??null))return self::error('seal');$seal=$r['seal'];unset($r['seal']);if(!hash_equals($seal,hash_hmac('sha256',Validator::canonicalJson($r),wp_salt('auth')))||!self::access((string)($r['shop_key']??''),(string)($r['operation']??'')))return self::error('access');return $r;}catch(\Throwable){return self::error('integrity');}
 }
 public function prepare(string $shop,int $integration,string $operation,array $input,string $readReceipt=''):array|WP_Error{
  if(!self::access($shop,$operation)||!in_array($operation,['SHIPPING_QUOTE','ORDER_CANCEL','LINE_ITEM_CANCEL','WEBHOOK_READ','WEBHOOK_CREATE','WEBHOOK_UPDATE','WEBHOOK_DELETE'],true))return self::error('access_or_operation');
  $scope=PrintifyConnectionBoundary::context($shop,$integration,self::CAPABILITY);if(is_wp_error($scope))return $scope;
  $i=(new \DigiForge\Integrations\Repository())->find($integration);if(is_wp_error($i)||!is_array($i)||($i['provider']??null)!=='printify'||($i['config']['shop_key']??null)!==$shop||!is_string($i['config']['shop_id']??null))return self::error('integration');
  try{$request=PrintifyLifecycleRequest::prepare($operation,$i['config']['shop_id'],$input);if(isset($input['binding'])){$entry=PrintifyWebhookRequest::binding($input['binding']);if($entry['shop_key']!==$shop||$entry['integration_id']!==$integration||(string)$entry['provider_shop_id']!==$i['config']['shop_id'])return self::error('webhook_scope');}}catch(\Throwable){return self::error('contract');}
  $reference=null;
  if(in_array($operation,['ORDER_CANCEL','LINE_ITEM_CANCEL'],true)){
   $r=(new PrintifyApiReadWorkflow())->receipt($readReceipt);if(is_wp_error($r)||$r['status']!=='SUCCEEDED'||$r['operation']!=='ORDER_READ'||$r['shop_key']!==$shop||$r['integration_id']!==$integration||($r['data']['id']??null)!==($input['order_id']??null)||!in_array($r['data']['status']??null,$operation==='ORDER_CANCEL'?['on-hold','payment-not-received']:['pending','on-hold','payment-not-received','has-issues','source-check-failed'],true)||strtotime($r['observed_at'])<time()-300)return self::error('fresh_cancelable_order_evidence');
   $readScope=PrintifyConnectionBoundary::context($shop,$integration,PrintifyApiReadWorkflow::CAPABILITY);if(is_wp_error($readScope))return $readScope;
   if(($r['provider_shop_id']??null)!==$i['config']['shop_id']||($r['connection_binding_hash']??null)!==$readScope['binding_hash']||($r['external_account_hash']??null)!==hash('sha256',$scope['external_account_reference'])||strtotime($r['observed_at'])>time())return self::error('cancel_scope');
   if($operation==='LINE_ITEM_CANCEL'){$matches=array_values(array_filter($r['data']['line_items']??[],static fn($line)=>($line['id']??null)===$input['line_item_id']));if(count($matches)!==1||!in_array($matches[0]['status']??null,['pending','on-hold','payment-not-received','has-issues','source-check-failed'],true))return self::error('fresh_cancelable_line_evidence');}
   $reference=['receipt_hash'=>$readReceipt,'observed_at'=>$r['observed_at'],'status'=>$r['data']['status']];
  }
  $saved=$this->save('plan',['schema'=>'printify-lifecycle-v1','shop_key'=>$shop,'integration_id'=>$integration,'provider_shop_id'=>$i['config']['shop_id'],'operation'=>$operation,'input'=>$input,'request'=>$request,'connection_scope'=>$scope,'reference'=>$reference,'intent_id'=>bin2hex(random_bytes(8)),'created_by'=>get_current_user_id(),'created_at'=>time()]);
  if(is_wp_error($saved)||!Logger::write('printify_lifecycle_prepared',['plan_hash'=>is_wp_error($saved)?null:$saved['hash'],'operation'=>$operation],'printify_lifecycle',is_wp_error($saved)?'':$saved['hash']))return self::error('plan_audit');return $saved;
 }
 public function plan(string $hash):array|WP_Error{return $this->load('plan',$hash);}
 public function approvalPayload(string $hash):array|WP_Error{
  $p=$this->plan($hash);if(is_wp_error($p))return $p;return ['purpose'=>'printify-lifecycle-v1','plan_hash'=>$hash,'request_fingerprint'=>$p['request']['fingerprint'],'connection_binding_hash'=>$p['connection_scope']['binding_hash'],'required_scope'=>$p['request']['required_scope'],'max_requests'=>1,'read_reference'=>$p['reference']['receipt_hash']??null];
 }
 private function current(string $hash,array $p):array|WP_Error{
  if(Settings::safety_locked()||Settings::get('stop_all',true)!==false||Settings::get('printify_activation_authorized',false)!==true||!Settings::is_enabled('printify'))return self::error('locked');
  if(!self::access($p['shop_key'],$p['operation'])||Owner::require($p['shop_key'])!==true)return self::error('owner');
  $bound=PrintifyConnectionBoundary::runtime($p['integration_id'],self::CAPABILITY,$p['connection_scope'],$p['provider_shop_id']);if(is_wp_error($bound))return $bound;
  if(in_array($p['operation'],['ORDER_CANCEL','LINE_ITEM_CANCEL'],true)&&strtotime($p['reference']['observed_at']??'')<time()-300)return self::error('stale_order');
  $policy=ReviewedLocalEvidence::approved('policy',$p['shop_key'],'printify-lifecycle:'.$hash);$expected=$this->approvalPayload($hash);if(is_wp_error($policy)||is_wp_error($expected)||Validator::canonicalJson($policy['payload']??[])!==Validator::canonicalJson($expected))return self::error('exact_approval');return $policy;
 }
 public function authorize(string $hash):array|WP_Error{
  $p=$this->plan($hash);if(is_wp_error($p))return $p;$policy=$this->current($hash,$p);if(is_wp_error($policy))return $policy;
  return $this->save('authorization',['shop_key'=>$p['shop_key'],'operation'=>$p['operation'],'plan_hash'=>$hash,'policy_hash'=>$policy['proposal_hash'],'actor'=>get_current_user_id(),'nonce'=>bin2hex(random_bytes(24)),'issued_at'=>time(),'expires_at'=>time()+300]);
 }
 public function receipt(string $hash):array|WP_Error{
  $r=$this->load('receipt',$hash);if(is_wp_error($r))return $r;global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE object_type=%s AND object_id=%s AND event_type=%s LIMIT 2','printify_lifecycle',$r['plan_hash'],'printify_lifecycle_receipt'));if($wpdb->last_error!==''||!is_array($rows)||count($rows)!==1)return self::error('receipt_audit');$a=json_decode($rows[0],true);return is_array($a)&&($a['receipt_hash']??null)===$hash?$r:self::error('receipt_audit');
 }
 public function execute(string $hash,string $authorization,callable $sender):array|WP_Error{
  $durable=PrintifyProductPipeline::durableDispatchSession();if(is_wp_error($durable))return $durable;$p=$this->plan($hash);$a=$this->load('authorization',$authorization);if(is_wp_error($p)||is_wp_error($a))return self::error('records');$policy=$this->current($hash,$p);if(is_wp_error($policy))return $policy;
  if($a['plan_hash']!==$hash||$a['actor']!==get_current_user_id()||$a['policy_hash']!==$policy['proposal_hash']||$a['issued_at']>time()||$a['expires_at']<=time()||$a['expires_at']-$a['issued_at']>300)return self::error('authorization');
  try{$request=PrintifyLifecycleRequest::prepare($p['operation'],$p['provider_shop_id'],$p['input']);if(Validator::canonicalJson($request)!==Validator::canonicalJson($p['request']))return self::error('request_changed');}catch(\Throwable){return self::error('request_changed');}
  if($p['operation']==='WEBHOOK_CREATE'){$intent=PrintifyOperationClaim::claim($p['connection_scope'],'WEBHOOK_CREATE',$request['fingerprint'],$request['fingerprint']);if(is_wp_error($intent))return $intent;}
  $claim=in_array($p['operation'],['WEBHOOK_READ','SHIPPING_QUOTE'],true)?$hash:hash('sha256',$p['connection_scope']['binding_hash'].':'.$request['fingerprint']);if(!add_option('digiforge_pfy_lifecycle_claim_'.$claim,$authorization,'',false))return self::error('consumed');
  $nonce=ExecutionNonceLedger::consume($a['nonce'],$authorization,get_current_user_id());if(is_wp_error($nonce))return $nonce;
  if(!Logger::write('printify_lifecycle_reserved',['plan_hash'=>$hash,'fingerprint'=>$request['fingerprint'],'authorization_hash'=>$authorization],'printify_lifecycle',$hash))return self::error('audit');
  $attempted=false;$result=(new PrintifyScopedCredentialRetriever())->consume($p['integration_id'],function(string $token)use($hash,$p,$request,$sender,&$attempted){
   $fresh=$this->current($hash,$p);if(is_wp_error($fresh))return $fresh;$correlation=$hash.':0';$quota=PrintifyAccountQuota::reserve($p['connection_scope'],$request['endpoint'],$request['method'],$correlation);if(is_wp_error($quota))return $quota;
   $send=function(?string $secret=null)use($hash,$p,$request,$sender,$token,&$attempted){$fresh=$this->current($hash,$p);if(is_wp_error($fresh))return $fresh;$durable=PrintifyProductPipeline::durableDispatchSession();if(is_wp_error($durable))return $durable;if($secret!==null&&!hash_equals((string)$request['secret_fingerprint'],hash('sha256',$secret)))throw new \RuntimeException('Signing material changed');$body=$request['body'];if($request['operation']==='WEBHOOK_CREATE')$body['secret']=$secret;$args=['method'=>$request['method'],'timeout'=>20,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>1048577,'headers'=>['Authorization'=>'Bearer '.$token,'User-Agent'=>'DigiForge/PrintifyApi','Content-Type'=>'application/json','Accept'=>'application/json']];if($body!==[])$args['body']=json_encode($body,JSON_THROW_ON_ERROR);$attempted=true;$response=$sender('https://api.printify.com'.$request['endpoint'],$args);if($secret!==null&&!is_wp_error($response)&&str_contains((string)wp_remote_retrieve_body($response),$secret))return new WP_Error('printify_webhook_protected_response');return $response;};
   try{$response=$request['operation']==='WEBHOOK_CREATE'?PrintifyWebhookRequest::withSecret($p['input']['binding'],$p['shop_key'],$p['integration_id'],$send):$send();}catch(\Throwable){$response=new WP_Error('printify_transport_unknown');}
   $status=is_wp_error($response)?0:(int)wp_remote_retrieve_response_code($response);$headers=is_wp_error($response)?[]:['retry-after'=>(string)wp_remote_retrieve_header($response,'retry-after')];$observed=PrintifyAccountQuota::observe($p['connection_scope'],$status,$headers,$correlation);$classification=PrintifyApiOutcome::classify($status,$headers,1,$request['method']);
   if(is_wp_error($observed)||is_wp_error($response)||$this->current($hash,$p) instanceof WP_Error)return ['status'=>'UNKNOWN','category'=>'EVIDENCE_UNAVAILABLE','data'=>null];
   if($classification['category']!=='SUCCESS')return ['status'=>$classification['category']==='DEFINITIVE_ERROR'?'FAILED':'UNKNOWN','category'=>$classification['category'],'data'=>null,'http_status'=>$status];
   try{$raw=(string)wp_remote_retrieve_body($response);if(strlen($raw)>1048576)throw new \LengthException();if($request['operation']==='LINE_ITEM_CANCEL')return ['status'=>'SUCCEEDED','category'=>'SUCCESS','data'=>PrintifyLifecycleEvidence::emptyCancellation($request,$status,$raw),'response_sha256'=>hash('sha256',$raw)];$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($data)||PrintifyCatalogReadContract::containsProtectedMaterial($data,$token))throw new \UnexpectedValueException();$projected=PrintifyLifecycleEvidence::project($request,$data);return ['status'=>'SUCCEEDED','category'=>'SUCCESS','data'=>$projected,'response_sha256'=>hash('sha256',$raw)];}catch(\Throwable){return ['status'=>'UNKNOWN','category'=>'RESPONSE_REJECTED','data'=>null];}
  },$p['connection_scope']);
  if(is_wp_error($result)){if(!$attempted)return $result;$result=['status'=>'UNKNOWN','category'=>'CREDENTIAL_EVIDENCE_CHANGED','data'=>null];}
  $r=$this->save('receipt',$result+['shop_key'=>$p['shop_key'],'operation'=>$p['operation'],'plan_hash'=>$hash,'integration_id'=>$p['integration_id'],'provider_shop_id'=>$p['provider_shop_id'],'external_account_hash'=>hash('sha256',$p['connection_scope']['external_account_reference']),'connection_binding_hash'=>$p['connection_scope']['binding_hash'],'network_request_attempted'=>$attempted,'request_fingerprint'=>$request['fingerprint'],'reconciliation_identity'=>array_intersect_key($p['input'],array_flip(['order_id','line_item_id'])),'observed_at'=>gmdate('c'),'retry_permitted'=>false]);
  if(is_wp_error($r)||!Logger::write('printify_lifecycle_receipt',['plan_hash'=>$hash,'receipt_hash'=>is_wp_error($r)?null:$r['hash'],'status'=>$result['status']],'printify_lifecycle',$hash))return self::error('receipt_unknown');return $r;
 }
 /** Reconcile immutable supplier observations locally; never changes claims or grants retry authority. */
 private function sequence(array $receipt,string $event):int{
  global $wpdb;$wpdb->last_error='';$id=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Tables::audit_log().' WHERE event_type=%s AND object_id=%s ORDER BY id DESC LIMIT 1',$event,$receipt['plan_hash']));return $wpdb->last_error===''?(int)$id:0;
 }
 public function reconcile(string $receiptHash,string $readbackHash):array|WP_Error{
  $r=$this->receipt($receiptHash);if(is_wp_error($r)||Owner::require($r['shop_key'])!==true)return self::error('reconcile_owner');$p=$this->plan($r['plan_hash']);if(is_wp_error($p))return $p;
  $webhook=str_starts_with($r['operation'],'WEBHOOK_');$read=$webhook?$this->receipt($readbackHash):(new PrintifyApiReadWorkflow())->receipt($readbackHash);
  if(is_wp_error($read)||$read['status']!=='SUCCEEDED'||$read['shop_key']!==$r['shop_key']||$read['integration_id']!==$r['integration_id']||($read['provider_shop_id']??null)!==$p['provider_shop_id']||($read['external_account_hash']??null)!==hash('sha256',$p['connection_scope']['external_account_reference']))return self::error('readback_scope');
  $earlier=$this->sequence($r,'printify_lifecycle_receipt');$later=$this->sequence($read,$webhook?'printify_lifecycle_receipt':'printify_api_read_receipt');if($earlier<1||$later<=$earlier||strtotime($read['observed_at'])<time()-300)return self::error('readback_not_newer');
  $disposition='NOT_CONFIRMED';
  if($webhook){
   if($read['operation']!=='WEBHOOK_READ')return self::error('subscription_read_required');$rows=$read['data']['subscriptions'];$matches=[];
   foreach($rows as $row){$same=$r['operation']==='WEBHOOK_CREATE'?($row['topic']===($p['request']['body']['topic']??null)&&$row['url']===($p['request']['body']['url']??null)):($row['id']===($p['input']['webhook_id']??null));if($same)$matches[]=$row;}
   if($r['operation']==='WEBHOOK_DELETE')$disposition=$matches===[]?'CONFIRMED_ABSENT':'NOT_CONFIRMED';
   elseif(count($matches)===1&&$matches[0]['url']===($p['request']['body']['url']??null))$disposition='CONFIRMED_BY_READBACK';
   elseif(count($matches)>1)$disposition='AMBIGUOUS_DUPLICATE';
  }elseif($r['operation']==='ORDER_CANCEL'&&$read['operation']==='ORDER_READ'&&($read['data']['id']??null)===($p['input']['order_id']??null)&&($read['data']['status']??null)==='canceled')$disposition='CONFIRMED_BY_READBACK';
  if($r['operation']==='LINE_ITEM_CANCEL'&&$read['operation']==='ORDER_READ'&&($read['data']['id']??null)===$p['input']['order_id']){$matches=array_values(array_filter($read['data']['line_items']??[],static fn($line)=>($line['id']??null)===$p['input']['line_item_id']));if(count($matches)===1&&($matches[0]['status']??null)==='canceled')$disposition='CONFIRMED_BY_READBACK';}
  $e=['shop_key'=>$r['shop_key'],'operation'=>$r['operation'],'receipt_hash'=>$receiptHash,'readback_hash'=>$readbackHash,'disposition'=>$disposition,'webhook_secret_live_verified'=>false,'retry_authorized'=>false,'observed_at'=>gmdate('c')];$saved=$this->save('reconciliation',$e);return !is_wp_error($saved)&&Logger::write('printify_lifecycle_reconciled',['receipt_hash'=>$receiptHash,'readback_hash'=>$readbackHash,'disposition'=>$disposition],'printify_lifecycle',$r['plan_hash'])?$saved:self::error('reconciliation_audit');
 }
}
