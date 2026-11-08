<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Core\Settings;
use DigiForge\Listings\Validator;
use DigiForge\Security\{ShopAccess,ReviewedLocalEvidence,Logger};
use DigiForge\PortalAccess\Owner;
use DigiForge\Research\DesignFactoryRepository;
use WP_Error;
/** Product preparation only. Existing catalog/order grants never authorize this pipeline. */
final class PrintifyProductPipeline {
 public const CAPABILITY='printify.product.prepare';
 private static function access():bool{return Shop2ProductWorkflow::access(true)&&ShopAccess::authorize('personalized_pod','manage_digiforge_connections')===true;}
 public static function locked():bool{return Settings::safety_locked()||Settings::get('stop_all',true)!==false||Settings::get('printify_activation_authorized',false)!==true||!Settings::is_enabled('printify');}
 public static function error(string $code):WP_Error{return new WP_Error('printify_product_'.$code,'Printify product operation blocked: '.$code.'.',['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
 private function put(string $kind,array $r):array|WP_Error {
  $r['seal']=hash_hmac('sha256',Validator::canonicalJson($r),wp_salt('auth'));$raw=Validator::canonicalJson($r);if(strlen($raw)>3300000)return self::error('record_size');$hash=hash('sha256',$raw);$key='digiforge_pp_'.$kind.'_'.$hash;
  if(!add_option($key,$raw,'',false)&&get_option($key)!==$raw)return self::error('persistence');return ['hash'=>$hash,'record'=>$r];
 }
 private function get(string $kind,string $hash):array|WP_Error {
  if(!self::access())return self::error('access');if(!preg_match('/^[a-f0-9]{64}$/D',$hash))return self::error('hash');global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s','digiforge_pp_'.$kind.'_'.$hash));
  if($wpdb->last_error!==''||!is_string($raw)||strlen($raw)>3300000||!hash_equals($hash,hash('sha256',$raw)))return self::error('record_integrity');$r=json_decode($raw,true);if(!is_array($r)||!is_string($r['seal']??null))return self::error('record_schema');$seal=$r['seal'];unset($r['seal']);if(!hash_equals($seal,hash_hmac('sha256',Validator::canonicalJson($r),wp_salt('auth'))))return self::error('record_seal');
  if($kind==='receipt'&&($r['audit_required']??false)===true){$wpdb->last_error='';$event=isset($r['source_read_receipt'])?'printify_product_read_adopted':'printify_product_receipt';$rows=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.\DigiForge\Database\Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s LIMIT 2',$event,'printify_product',$r['plan_hash']));if($wpdb->last_error!==''||!is_array($rows)||$rows===[])return self::error('receipt_audit');$found=false;foreach($rows as $rawAnchor){$anchor=json_decode($rawAnchor,true);if(is_array($anchor)&&($anchor['receipt_hash']??null)===$hash)$found=true;}if(!$found)return self::error('receipt_audit');}
  return $r;
 }
 public function prepare(string $workflow,string $package,int $integration,string $operation,array $options):array|WP_Error {
  if(!self::access())return self::error('access');
  $link=(new Shop2ProductWorkflow())->read($workflow);if(is_wp_error($link))return $link;$raw=(new Shop2ProductWorkflow())->export($link,$package);if(is_wp_error($raw))return $raw;$export=json_decode($raw,true);
  $i=(new \DigiForge\Integrations\Repository())->find($integration);if(is_wp_error($i))return $i;
  if(!is_array($i)||$i['provider']!=='printify'||($i['config']['shop_key']??null)!=='personalized_pod'||!is_string($i['config']['shop_id']??null))return self::error('integration_scope');
  $scope=PrintifyConnectionBoundary::context('personalized_pod',$integration,self::CAPABILITY);
  try{$input=$this->input($export,$workflow,$package,$integration,$operation,$options,$i['config']['shop_id'],is_wp_error($scope)?null:$scope['binding_hash']);if(is_wp_error($input))return $input;$input=json_decode(Validator::canonicalJson($input),true,32,JSON_THROW_ON_ERROR);$request=PrintifyProductRequest::prepare($operation,$i['config']['shop_id'],$input);}catch(\Throwable){return self::error('request_contract');}
  $r=['schema'=>'printify-product-plan-v1','workflow'=>$workflow,'package'=>$package,'integration_id'=>$integration,'shop_id'=>$i['config']['shop_id'],'environment'=>$i['environment'],'connection_scope'=>is_wp_error($scope)?null:$scope,'binding_state'=>is_wp_error($scope)?'UNVERIFIED_LOCAL_PREVIEW':'VERIFIED_LOCAL_BINDING','selection'=>array_intersect_key($export['supplier_selection'],array_flip(['blueprint_id','provider_id','variant_id'])),'request'=>$request,'options'=>$options,'created_by'=>get_current_user_id(),'created_at'=>gmdate('c'),'external_execution_authorized'=>false];
  $saved=$this->put('plan',$r);if(is_wp_error($saved))return $saved;
  if(!Logger::write('printify_product_plan_prepared',['plan_sha256'=>$saved['hash'],'operation'=>$operation,'package_sha256'=>$package,'network_request_attempted'=>false],'printify_product',$saved['hash']))return self::error('audit');return $saved;
 }
 /** Server-resolved approved package, uploaded image and readback identities only. */
 private function input(array $e,string $workflow,string $package,int $integration,string $op,array $o,string $shop,?string $binding):array|WP_Error {
  $s=$e['supplier_selection'];$p=$e['package'];$design=$e['commercial_design_metadata']['design_hash'];
  $ref=function(string $key,array $ops)use($o,$workflow,$package,$integration,$shop,$binding){if(!is_string($o[$key]??null))return self::error('receipt_required');$r=$this->get('receipt',$o[$key]);if(is_wp_error($r))return $r;
   if(($r['status']??null)!=='SUCCEEDED'||($r['workflow']??null)!==$workflow||!$this->packageLineage($r['package']??null,$package)||($r['integration_id']??null)!==$integration||($r['shop_id']??null)!==$shop||($r['connection_binding_hash']??null)!==$binding||!in_array($r['operation']??null,$ops,true))return self::error('receipt_scope');return $r['data'];};
  $fields=match($op){'IMAGE_UPLOAD'=>['asset_reference'],'IMAGE_READ'=>['image_receipt'],'PRODUCT_CREATE'=>['image_receipt','price_minor','placement'],'PRODUCT_UPDATE'=>['product_receipt','price_minor'],'PRODUCT_PUBLISH'=>['product_receipt','publish_fields'],'PRODUCT_PUBLISH_SUCCEEDED'=>['product_receipt','external'],'PRODUCT_PUBLISH_FAILED'=>['product_receipt','reason'],'PRODUCT_DELETE','PRODUCT_UNPUBLISH_NOTIFY'=>['product_receipt'],'PRODUCT_READ','PERSONALIZATION_OPTIONS'=>['product_receipt'],'PERSONALIZATION_CONFIGURE'=>['product_receipt','options_receipt','items','photo_receipts'],'PREVIEW_CREATE'=>['product_receipt','options_receipt','items','photo_receipts','external_id','format'],'PREVIEW_READ'=>['product_receipt','preview_receipt'],default=>[]};
  if($fields===[]||count($o)!==count($fields)||array_diff(array_keys($o),$fields))return self::error('exact_options');
  if($op==='IMAGE_UPLOAD'){
   if(!is_string($o['asset_reference']))return self::error('asset');$r=(new DesignFactoryRepository())->raster('personalized_pod',$design,$o['asset_reference']);if(is_wp_error($r))return $r;
   $g=(new Shop2ProductWorkflow())->geometry(['record'=>$s,'review'=>$s['catalog_evidence']],$r['metadata']['width_px'],$r['metadata']['height_px']);if(is_wp_error($g))return $g;
   return ['file_name'=>'shop2-'.substr($package,0,16).'.png','contents'=>$r['base64']];
  }
  if($op==='IMAGE_READ'){$img=$ref('image_receipt',['IMAGE_UPLOAD','IMAGE_READ']);return is_wp_error($img)?$img:['image_id'=>$img['id']];}
  if($op==='PRODUCT_CREATE'){
   $img=$ref('image_receipt',['IMAGE_UPLOAD','IMAGE_READ']);if(is_wp_error($img))return $img;if(!is_int($o['price_minor'])||!is_array($o['placement'])||array_diff(array_keys($o['placement']),['x','y','scale','angle'])||count($o['placement'])!==4)return self::error('placement_and_price');
   $area=$p['provider']['template']['print_areas'][0];if($img['width']!==$area['width_px']||$img['height']!==$area['height_px'])return self::error('image_geometry');
   return ['title'=>$p['listing']['title'],'description'=>$p['listing']['description'],'blueprint_id'=>$s['blueprint_id'],'print_provider_id'=>$s['provider_id'],'variants'=>[['id'=>$s['variant_id'],'price'=>$o['price_minor'],'is_enabled'=>true]],'print_areas'=>[['variant_ids'=>[$s['variant_id']],'placeholders'=>[['position'=>$area['position'],'images'=>[['id'=>$img['id']]+$o['placement']]]]]]];
  }
  $product=$ref('product_receipt',['PRODUCT_CREATE','PRODUCT_READ','PRODUCT_UPDATE']);if(is_wp_error($product))return $product;$id=$product['id'];
  if(in_array($op,['PRODUCT_PUBLISH','PRODUCT_DELETE','PRODUCT_UNPUBLISH_NOTIFY','PRODUCT_PUBLISH_SUCCEEDED','PRODUCT_PUBLISH_FAILED'],true)){
   if(!current_user_can('manage_digiforge_listings')||\DigiForge\Security\ShopAccess::authorize('personalized_pod','manage_digiforge_listings')!==true)return self::error('listing_scope');
   return ['product_id'=>$id]+match($op){'PRODUCT_PUBLISH'=>['publish_fields'=>$o['publish_fields']],'PRODUCT_PUBLISH_SUCCEEDED'=>['external'=>$o['external']],'PRODUCT_PUBLISH_FAILED'=>['reason'=>$o['reason']],default=>[]};
  }
  if($op==='PRODUCT_READ'||$op==='PERSONALIZATION_OPTIONS')return ['product_id'=>$id];
  if($op==='PRODUCT_UPDATE'){
   $variants=$product['variants'];foreach($variants as &$v)if($v['id']===$s['variant_id'])$v['price']=$o['price_minor'];unset($v);return ['product_id'=>$id,'variants'=>$variants,'known_variant_ids'=>array_column($variants,'id')];
  }
  if($op==='PREVIEW_READ'){$preview=$ref('preview_receipt',['PREVIEW_CREATE']);if(is_wp_error($preview))return $preview;if(($preview['product_id']??null)!==$id)return self::error('product_scope');return ['product_id'=>$id,'task_id'=>$preview['task_id']];}
  $native=$ref('options_receipt',['PERSONALIZATION_OPTIONS']);if(is_wp_error($native))return $native;if(($native['product_id']??null)!==$id||!is_array($o['items'])||!array_is_list($o['items'])||!is_array($o['photo_receipts']))return self::error('native_fields');
  $available=array_column($native['fields'],null,'field_id');$seen=[];
  foreach($o['items'] as $item){$field=$available[$item['field_id']??'']??null;if($field===null||$item['type']!==$field['type'])return self::error('native_field_scope');$seen[]=$item['field_id'];
   if($item['type']==='textbox'){if(!is_string($item['input']['text']??null)||!is_int($field['character_limit']??null)||mb_strlen($item['input']['text'])>$field['character_limit'])return self::error('native_text_limit');}
   else{$ok=false;foreach($o['photo_receipts'] as $photo){$getter=function()use($photo,$workflow,$package,$integration,$shop,$binding){$r=is_string($photo)?$this->get('receipt',$photo):self::error('photo_receipt');if(is_wp_error($r))return $r;return ($r['workflow']===$workflow&&$this->packageLineage($r['package']??null,$package)&&$r['integration_id']===$integration&&($r['shop_id']??null)===$shop&&($r['connection_binding_hash']??null)===$binding&&$r['status']==='SUCCEEDED'&&in_array($r['operation'],['IMAGE_UPLOAD','IMAGE_READ'],true))?$r['data']:self::error('photo_scope');};$image=$getter();if(!is_wp_error($image)&&($image['preview_url']??null)===($item['input']['image_url']??null))$ok=true;}if(!$ok)return self::error('photo_source');}
  }
  $known=array_keys($available);sort($known);sort($seen);if($known!==$seen)return self::error('all_native_fields_required');
  if($op==='PERSONALIZATION_CONFIGURE')return ['product_id'=>$id,'variant_id'=>$s['variant_id'],'items'=>$o['items'],'require_all_personalization_fields'=>true];
  return ['product_id'=>$id,'variant_ids'=>[$s['variant_id']],'personalization'=>$o['items'],'external_id'=>$o['external_id'],'format'=>$o['format']];
 }
 /** Only an explicitly approved ancestor with identical artwork and provider material can share IDs. */
 private function packageLineage(mixed $origin,string $target):bool {
  if(!is_string($origin))return false;if($origin===$target)return true;
  $repo=new ProductPackageRepository();$history=$repo->history('personalized_pod',$target);if(is_wp_error($history)||!in_array($origin,array_column($history,'hash'),true))return false;
  $parent=$repo->read('personalized_pod',$origin);$child=$repo->read('personalized_pod',$target);if(is_wp_error($parent)||is_wp_error($child)||($parent['decision']['state']??null)!=='HUMAN_APPROVED'||($child['decision']['state']??null)!=='HUMAN_APPROVED'||$parent['record']['artwork_version_hash']!==$child['record']['artwork_version_hash'])return false;
  try{$a=$parent['record']['package']['provider']['template'];$b=$child['record']['package']['provider']['template'];return is_array($a)&&is_array($b)&&ProductionTemplateContract::materialFingerprint($a)===ProductionTemplateContract::materialFingerprint($b);}catch(\Throwable){return false;}
 }
 public function adoptReadReceipt(string $workflow,string $package,int $integration,string $readHash):array|WP_Error {
  if(!self::access()||Owner::require('personalized_pod')!==true)return self::error('owner');
  $link=(new Shop2ProductWorkflow())->read($workflow);if(is_wp_error($link))return $link;$raw=(new Shop2ProductWorkflow())->export($link,$package);if(is_wp_error($raw))return $raw;$export=json_decode($raw,true);
  $read=(new PrintifyApiReadWorkflow())->receipt($readHash);$scope=PrintifyConnectionBoundary::context('personalized_pod',$integration,self::CAPABILITY);if(is_wp_error($read)||is_wp_error($scope))return self::error('read_scope');
  $s=$export['supplier_selection'];$data=$read['data']??null;
  if($read['status']!=='SUCCEEDED'||$read['operation']!=='PRODUCT_READ'||$read['shop_key']!=='personalized_pod'||$read['integration_id']!==$integration||($read['provider_shop_id']??null)!==$scope['external_shop_reference']||($read['external_account_hash']??null)!==hash('sha256',$scope['external_account_reference'])||!is_array($data)||$data['blueprint_id']!==$s['blueprint_id']||$data['print_provider_id']!==$s['provider_id']||!in_array($s['variant_id'],array_column($data['variants'],'id'),true)||strtotime($read['observed_at'])<time()-300)return self::error('read_mapping');
  $request=PrintifyProductRequest::prepare('PRODUCT_READ',$scope['external_shop_reference'],['product_id'=>$data['id']]);
  $plan=$this->put('plan',['schema'=>'printify-product-plan-v1','workflow'=>$workflow,'package'=>$package,'integration_id'=>$integration,'shop_id'=>$scope['external_shop_reference'],'environment'=>$scope['environment'],'binding_state'=>'VERIFIED_LOCAL_BINDING','connection_scope'=>$scope,'request'=>$request,'selection'=>array_intersect_key($s,array_flip(['blueprint_id','provider_id','variant_id'])),'options'=>[],'created_by'=>get_current_user_id(),'created_at'=>gmdate('c'),'adopted_read_receipt'=>$readHash,'external_execution_authorized'=>false]);if(is_wp_error($plan))return $plan;
  $receipt=$this->put('receipt',['status'=>'SUCCEEDED','data'=>$data,'plan_hash'=>$plan['hash'],'workflow'=>$workflow,'package'=>$package,'integration_id'=>$integration,'shop_id'=>$scope['external_shop_reference'],'connection_binding_hash'=>$scope['binding_hash'],'operation'=>'PRODUCT_READ','observed_at'=>$read['observed_at'],'network_request_attempted'=>false,'source_read_receipt'=>$readHash,'audit_required'=>true,'retry_permitted'=>false]);
  return !is_wp_error($receipt)&&Logger::write('printify_product_read_adopted',['source_read_receipt'=>$readHash,'receipt_hash'=>$receipt['hash'],'plan_hash'=>$plan['hash']],'printify_product',$plan['hash'])?$receipt:self::error('read_adoption_audit');
 }
 public function plan(string $hash):array|WP_Error {
  $p=$this->get('plan',$hash);if(is_wp_error($p))return $p;if(!is_array($p['request']??null)||!PrintifyProductRequest::intact($p['request']))return self::error('request_integrity');$link=(new Shop2ProductWorkflow())->read($p['workflow']);if(is_wp_error($link))return $link;$valid=(new Shop2ProductWorkflow())->export($link,$p['package']);return is_wp_error($valid)?$valid:$p;
 }
 public function receipt(string $hash):array|WP_Error{$r=$this->get('receipt',$hash);if(is_wp_error($r))return $r;$p=$this->plan($r['plan_hash']);return is_wp_error($p)?$p:$r;}
 public function approvalPayload(string $hash):array|WP_Error {
  $p=$this->plan($hash);if(is_wp_error($p))return $p;return ['purpose'=>'printify-product-operation-v1','plan_hash'=>$hash,'request_fingerprint'=>$p['request']['fingerprint'],'package_hash'=>$p['package'],'integration_id'=>$p['integration_id'],'connection_binding_hash'=>$p['connection_scope']['binding_hash']??null,'required_scope'=>$p['request']['required_scope']];
 }
 private function current(string $hash,array $p):array|WP_Error {
  if(self::locked())return self::error('locked');if(!self::access()||Owner::require('personalized_pod')!==true)return self::error('owner');
  if(in_array($p['request']['operation'],['PRODUCT_PUBLISH','PRODUCT_UNPUBLISH_NOTIFY','PRODUCT_PUBLISH_SUCCEEDED','PRODUCT_PUBLISH_FAILED'],true)&&(!current_user_can('manage_digiforge_listings')||ShopAccess::authorize('personalized_pod','manage_digiforge_listings')!==true))return self::error('listing_scope');
  if(!is_array($p['connection_scope']))return self::error('ownership_required');$binding=PrintifyConnectionBoundary::runtime($p['integration_id'],self::CAPABILITY,$p['connection_scope'],$p['shop_id']);if(is_wp_error($binding))return $binding;
  $policy=ReviewedLocalEvidence::approved('policy','personalized_pod','printify-product:'.$hash);if(is_wp_error($policy))return $policy;$expected=$this->approvalPayload($hash);if(is_wp_error($expected)||Validator::canonicalJson($policy['payload'])!==Validator::canonicalJson($expected))return self::error('exact_approval');return $policy;
 }
 /** Explicit owner invocation only; no portal page calls this automatically. */
 public function authorize(string $hash):array|WP_Error {
  if(self::locked())return self::error('locked');$p=$this->plan($hash);if(is_wp_error($p))return $p;$policy=$this->current($hash,$p);if(is_wp_error($policy))return $policy;
  return $this->put('authorization',['plan_hash'=>$hash,'fingerprint'=>$p['request']['fingerprint'],'policy_hash'=>$policy['proposal_hash'],'actor'=>get_current_user_id(),'nonce'=>wp_generate_password(48,false,false),'issued_at'=>time(),'expires_at'=>time()+300]);
 }
 /** Never dispatch on an outer transaction or non-durable tables. No caller work is committed. */
 public static function durableDispatchSession():true|WP_Error {
  global $wpdb;$wpdb->last_error='';$session=$wpdb->get_row('SELECT @@session.autocommit AS autocommit, @@session.in_transaction AS active',ARRAY_A);
  if($wpdb->last_error!==''||!is_array($session)||(string)($session['autocommit']??'')!=='1'||(string)($session['active']??'')!=='0')return self::error('transaction_active_or_unavailable');
  foreach([$wpdb->options,\DigiForge\Database\Tables::audit_log(),\DigiForge\Database\Tables::pod_execution_nonces()] as $table){$wpdb->last_error='';$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if($wpdb->last_error!==''||strtoupper((string)$engine)!=='INNODB')return self::error('durable_storage_required');}return true;
 }
 /** Called only by the existing authenticated transport sender; one request per explicit invocation. */
 public function execute(string $hash,string $authorization,callable $sender):array|WP_Error {
  if(self::locked())return self::error('locked');$durable=self::durableDispatchSession();if(is_wp_error($durable))return $durable;$p=$this->plan($hash);if(is_wp_error($p))return $p;$policy=$this->current($hash,$p);if(is_wp_error($policy))return $policy;$a=$this->get('authorization',$authorization);if(is_wp_error($a))return $a;
  if($a['plan_hash']!==$hash||$a['fingerprint']!==$p['request']['fingerprint']||$a['policy_hash']!==$policy['proposal_hash']||$a['actor']!==get_current_user_id()||$a['issued_at']>time()||$a['expires_at']<=time()||$a['expires_at']-$a['issued_at']>300)return self::error('authorization');
  $request=$p['request'];if($request['operation']==='PRODUCT_CREATE'){$intent=PrintifyOperationClaim::claim($p['connection_scope'],'PRODUCT_CREATE',$request['fingerprint'],$request['fingerprint']);if(is_wp_error($intent))return $intent;}$claim=$request['method']==='GET'?$hash:hash('sha256',$p['package'].':'.$p['integration_id'].':'.$request['fingerprint']);
  if(!add_option('digiforge_pp_claim_'.$claim,$authorization,'',false))return self::error('already_claimed');
  $consumed=ExecutionNonceLedger::consume($a['nonce'],$authorization,get_current_user_id());if(is_wp_error($consumed))return $consumed;
  $slot=false;for($n=0;$n<10;$n++)if(add_option('digiforge_pp_rate_'.$p['integration_id'].'_'.intdiv(time(),60).'_'.$n,$hash,'',false)){$slot=true;break;}if(!$slot)return self::error('rate_limit');
  if(!Logger::write('printify_product_dispatch_reserved',['plan_hash'=>$hash,'authorization_hash'=>$authorization,'request_fingerprint'=>$request['fingerprint']],'printify_product',$hash))return self::error('audit');
  $attempted=false;
  $result=(new PrintifyScopedCredentialRetriever())->consume($p['integration_id'],function(string $token)use($hash,$p,$request,$sender,&$attempted){
   $fresh=$this->current($hash,$p);if(is_wp_error($fresh))return $fresh;$material=PrintifyConnectionBoundary::material($p['integration_id'],$token);if(is_wp_error($material))return $material;
   $args=['method'=>$request['method'],'timeout'=>20,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>1048577,'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Accept'=>'application/json','User-Agent'=>'DigiForge/PrintifyApi']];if($request['body']!==[])$args['body']=json_encode($request['body'],JSON_THROW_ON_ERROR);
   $durable=self::durableDispatchSession();if(is_wp_error($durable))return $durable;
   $correlation='product:'.$hash;$quota=PrintifyAccountQuota::reserve($p['connection_scope'],$request['endpoint'],$request['method'],$correlation);if(is_wp_error($quota))return $quota;
   $attempted=true;try{$response=$sender('https://api.printify.com'.$request['endpoint'],$args);}catch(\Throwable){PrintifyAccountQuota::observe($p['connection_scope'],0,[],$correlation);return ['status'=>'UNKNOWN','data'=>[],'response_sha256'=>null];}
   $quotaStatus=is_wp_error($response)?0:(int)wp_remote_retrieve_response_code($response);$quotaObserved=PrintifyAccountQuota::observe($p['connection_scope'],$quotaStatus,is_wp_error($response)?[]:['retry-after'=>(string)wp_remote_retrieve_header($response,'retry-after')],$correlation);if(is_wp_error($quotaObserved))return ['status'=>'UNKNOWN','data'=>[],'response_sha256'=>null];
   if(is_wp_error($response))return ['status'=>'UNKNOWN','data'=>[],'response_sha256'=>null];$code=(int)wp_remote_retrieve_response_code($response);$raw=(string)wp_remote_retrieve_body($response);$digest=hash('sha256',$raw);
   if($code<200||$code>=300)return ['status'=>in_array($code,[400,401,403,404,422,429],true)?'FAILED':'UNKNOWN','http_status'=>$code,'data'=>[],'response_sha256'=>$digest];
   $data=json_decode($raw,true,32);if(!is_array($data)||strlen($raw)>1048576||PrintifyCatalogReadContract::containsProtectedMaterial($data,$token))return ['status'=>'UNKNOWN','data'=>[],'response_sha256'=>$digest];
   try{$projected=PrintifyProductResponse::project($p,$data);}catch(\Throwable){return ['status'=>'UNKNOWN','data'=>[],'response_sha256'=>$digest];}
   $fresh=$this->current($hash,$p);if(is_wp_error($fresh))return ['status'=>'UNKNOWN','data'=>[],'response_sha256'=>$digest];return ['status'=>'SUCCEEDED','data'=>$projected,'response_sha256'=>$digest];
  },$p['connection_scope']);
  if(is_wp_error($result)){if(!$attempted)return $result;$result=['status'=>'UNKNOWN','data'=>[],'response_sha256'=>null];}
  $saved=$this->put('receipt',$result+['plan_hash'=>$hash,'workflow'=>$p['workflow'],'package'=>$p['package'],'integration_id'=>$p['integration_id'],'operation'=>$request['operation'],'shop_id'=>$p['shop_id'],'connection_binding_hash'=>$p['connection_scope']['binding_hash'],'observed_at'=>gmdate('c'),'audit_required'=>true,'network_request_attempted'=>$attempted,'retry_permitted'=>false]);
  if(is_wp_error($saved)||!Logger::write('printify_product_receipt',['receipt_hash'=>$saved['hash'],'plan_hash'=>$hash,'operation'=>$request['operation'],'status'=>$result['status']],'printify_product',$hash))return new WP_Error('printify_product_receipt_unknown','Provider attempt requires reconciliation; receipt persistence unavailable.',['network_request_attempted'=>$attempted,'plan_hash'=>$hash,'retry_permitted'=>false]);return $saved;
 }
 /** Local desired-state observations never erase the original uncertain receipt or allow a retry. */
 public function reconcile(string $receiptHash,string $readHash):array|WP_Error {
  if(!self::access()||Owner::require('personalized_pod')!==true)return self::error('owner');$r=$this->get('receipt',$receiptHash);if(is_wp_error($r))return $r;$p=$this->plan($r['plan_hash']);$read=(new PrintifyApiReadWorkflow())->receipt($readHash);if(is_wp_error($p)||is_wp_error($read))return self::error('readback');
  if($read['shop_key']!=='personalized_pod'||$read['integration_id']!==$p['integration_id']||$read['operation']!=='PRODUCT_READ'||($read['provider_shop_id']??null)!==$p['shop_id']||($read['external_account_hash']??null)!==hash('sha256',$p['connection_scope']['external_account_reference'])||strtotime($read['observed_at'])<strtotime($r['observed_at'])||strtotime($read['observed_at'])<time()-300)return self::error('readback_scope');
  $disposition='UNKNOWN';$op=$p['request']['operation'];$endpoint=$p['request']['endpoint'];preg_match('#/products/([^/.]+)#',$endpoint,$m);$id=$m[1]??null;
  if($op==='PRODUCT_CREATE')$disposition='UNKNOWN_UNCORRELATED_CREATION';
  elseif($id!==null&&($read['endpoint_path']??null)==='/v1/shops/'.$p['shop_id'].'/products/'.$id.'.json'){
   if($op==='PRODUCT_DELETE'&&$read['status']==='FAILED'&&($read['reason_code']??null)==='NOT_FOUND')$disposition='CONFIRMED_ABSENT_BY_READBACK';
   elseif($op==='PRODUCT_UPDATE'&&$read['status']==='SUCCEEDED'){$body=$p['request']['body'];$actual=$read['data'];$same=array_diff(array_keys($body),['variants','print_areas'])===[];foreach($body as $key=>$expected)if(!is_array($expected)||!is_array($actual[$key]??null)||Validator::canonicalJson($expected)!==Validator::canonicalJson($actual[$key]))$same=false;$disposition=$same?'DESIRED_STATE_OBSERVED_NOT_CAUSAL':'NOT_CONFIRMED';}
   elseif(str_contains($op,'PUBLISH'))$disposition='EXTERNAL_CHANNEL_EVIDENCE_REQUIRED';
  }
  $e=['workflow'=>$p['workflow'],'package'=>$p['package'],'integration_id'=>$p['integration_id'],'receipt_hash'=>$receiptHash,'readback_hash'=>$readHash,'disposition'=>$disposition,'original_receipt_unchanged'=>true,'retry_authorized'=>false,'external_execution_authorized'=>false,'observed_at'=>gmdate('c')];$saved=$this->put('reconciliation',$e);return !is_wp_error($saved)&&Logger::write('printify_product_reconciled',['receipt_hash'=>$receiptHash,'readback_hash'=>$readHash,'disposition'=>$disposition],'printify_product',$r['plan_hash'])?$saved:self::error('reconciliation_audit');
 }
}
