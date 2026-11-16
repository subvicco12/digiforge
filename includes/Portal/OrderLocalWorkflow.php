<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Database\Tables;
use DigiForge\Connector\Printify\NativeSourceTransaction;
use DigiForge\Orders\Validator;
use DigiForge\Orders\HeldFulfillmentPreparation;
use DigiForge\Orders\Repository;
use WP_Error;

/** Purpose-bound local review only. Held intents remain BLOCKED; no provider execution permit. */
final class OrderLocalWorkflow
{
 public const BEGIN_REVIEW='digiforge_portal_order_local_begin_review';
 public const APPROVE='digiforge_portal_order_local_approve';
 public const DRAFT='digiforge_portal_order_local_draft_plan';
 public const BEGIN_PLAN_REVIEW='digiforge_portal_order_local_begin_plan_review';
 public const APPROVE_PLAN='digiforge_portal_order_local_approve_plan';
 public const PREPARE_INTENT='digiforge_portal_order_local_prepare_intent';
 private const PLAN_ACTIONS=[self::BEGIN_PLAN_REVIEW,self::APPROVE_PLAN,self::PREPARE_INTENT];
 public function register():void {foreach([self::BEGIN_REVIEW,self::APPROVE,self::DRAFT,...self::PLAN_ACTIONS]as $action)add_action('admin_post_'.$action,[$this,'handle']);}
 public static function parameters(string $action,array $body):array {
  if(in_array($action,self::PLAN_ACTIONS,true)){$raw=$body['plan_id']??null;return ['plan_id'=>(is_int($raw)&&$raw>0&&$raw<=2147483647)||(is_string($raw)&&preg_match('/^[1-9][0-9]{0,9}$/D',$raw)===1&&(int)$raw<=2147483647)?(int)$raw:0];}
  if($action!==self::DRAFT)return [];
  $params=[];foreach(['shipping_method','base_production_cost','shipping_estimate']as $key)$params[$key]=is_string($body[$key]??null)?trim($body[$key]):null;
  foreach(['base_production_cost','shipping_estimate']as $key)if(is_string($params[$key])&&preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,4})?$/D',$params[$key]))$params[$key]=number_format((float)$params[$key],4,'.','');
  return $params;
 }
 /** Full server-read evidence supplements the existing scoped held projection. */
 public static function inspect(string $action,int $id,string $shop,array $params):array|WP_Error {
  if(!in_array($action,[self::BEGIN_REVIEW,self::APPROVE,self::DRAFT,...self::PLAN_ACTIONS],true))return self::error('action','Exact local order action required.');
  if($action===self::DRAFT){
   if(!is_string($params['shipping_method']??null)||preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$params['shipping_method'])!==1)return self::error('method','Choose a bounded shipping method identifier for local review.');
   foreach(['base_production_cost','shipping_estimate']as $key)if(!is_string($params[$key]??null)||preg_match('/^(?:0|[1-9][0-9]{0,7})\.[0-9]{4}$/D',$params[$key])!==1)return self::error('cost','Enter nonnegative bounded local cost assumptions with at most four decimal places.');
  }
  $source=(new HeldFulfillmentPreparation())->forOrder($shop,$id);if(is_wp_error($source))return $source;
  $expected=match($action){self::BEGIN_REVIEW=>'VALIDATED',self::APPROVE=>'REVIEW_REQUIRED',default=>'APPROVED'};
  if($source['order']['state']!==$expected)return self::error('state','Current order state changed; this action requires '.$expected.'.');
  $checks=$source['readiness']['checks']??[];if(in_array($action,[self::BEGIN_REVIEW,self::APPROVE],true))unset($checks['order_approved'],$checks['human_approval']);
  if($checks===[]||in_array(false,$checks,true)||$source['lines']===[])return self::error('readiness','Current order readiness must pass before local review.');
  if(($action===self::DRAFT||in_array($action,self::PLAN_ACTIONS,true))&&((int)$source['order']['approved_by']<1||empty($source['order']['approved_at'])))return self::error('approval','An existing human order approval is required.');
  global $wpdb;
  try {
   $source['stored_order']=self::rows($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE id=%d',$id));
   $source['stored_lines']=self::rows($wpdb->prepare('SELECT * FROM '.Tables::order_line_items().' WHERE order_id=%d ORDER BY id LIMIT 101',$id));
   $source['stored_mapping_material']=[];
   foreach($source['lines']as $line){
    if($line['provider']!==$source['connection']['provider']||$line['mapping_environment']!==$source['order']['environment']||$line['currency']!==$source['order']['currency'])return self::error('mapping','Current mapped lines must agree with the reviewed connection, environment and stored order currency.');
    $source['stored_mapping_material'][]=self::rows($wpdb->prepare('SELECT * FROM '.Tables::pod_mappings().' WHERE id=%d',(int)$line['provider_mapping_id']));
   }
  }catch(\RuntimeException){return self::error('evidence','Complete current order evidence is unavailable.');}
  if(in_array($action,self::PLAN_ACTIONS,true)){$selected=self::currentPlan($source,(int)($params['plan_id']??0),$action);if(is_wp_error($selected))return $selected;$source['selected_plan']=$selected;}
  return $source;
 }
 public function processBrowser(array $body):array|WP_Error {
  $action=$body['action']??null;$raw=$body['order_id']??null;$shop=$body['shop_key']??null;
  if(!is_string($action)||!in_array($action,[self::BEGIN_REVIEW,self::APPROVE,self::DRAFT,...self::PLAN_ACTIONS],true)||!is_string($raw)||!preg_match('/^[1-9][0-9]{0,9}$/D',$raw)||(int)$raw>2147483647||!is_string($shop))return self::error('target','Exact action, shop and stored order ID required.');
  $id=(int)$raw;
  if(!is_string($body['_wpnonce']??null)||!wp_verify_nonce($body['_wpnonce'],$action.':'.$id))return self::error('nonce','Valid exact-target local order nonce required.');
  $params=self::parameters($action,$body);
  if(($body['phase']??null)==='preview'){
   $context=ReviewConfirmation::inspect($action,$id,$shop,$params);return is_wp_error($context)?$context:['preview'=>$context];
  }
  if(($body['phase']??null)!=='confirm')return self::error('phase','Preview current evidence before confirming.');
  $source=self::inspect($action,$id,$shop,$params);if(is_wp_error($source))return $source;
  global $wpdb;$wpdb->last_error='';$active=$wpdb->get_var('SELECT @@session.in_transaction');if($wpdb->last_error!==''||(string)$active!=='0')return self::error('transaction','Local review requires a clean database transaction boundary.');
  // Fixed local tables only: FOR UPDATE cannot establish atomicity on nontransactional storage.
  $tables=[Tables::orders(),Tables::order_line_items(),Tables::fulfillment_plans(),Tables::fulfillment_intents(),Tables::pod_mappings(),Tables::pod_business_mappings(),Tables::businesses(),Tables::stores(),Tables::product_programs(),Tables::personalization_schemas(),Tables::personalization_submissions(),Tables::integrations(),Tables::audit_log(),$wpdb->options,$wpdb->users,$wpdb->usermeta];
  try{$engines=self::rows($wpdb->prepare('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',array_fill(0,count($tables),'%s')).') ORDER BY TABLE_NAME',...$tables));}catch(\RuntimeException){return self::error('storage','Transactional storage evidence is unavailable.');}
  if(count($engines)!==count($tables))return self::error('storage','All local review tables require verified InnoDB storage.');foreach($engines as $engine)if(($engine['ENGINE']??null)!=='InnoDB')return self::error('storage','All local review tables require verified InnoDB storage.');
  // Consume before the business transaction: rollback must never restore a used acknowledgement.
  $receipt=ReviewConfirmation::accept($action,$id,$body);if(is_wp_error($receipt))return $receipt;
  global $wpdb;$wpdb->last_error='';if($wpdb->query('START TRANSACTION')===false)return self::error('transaction','Local review transaction is unavailable. Review current evidence again.');
  $committed=false;
  try {
   try{$this->lockSource($id,$source);}catch(\RuntimeException){return self::error('lock','Current order evidence could not be locked for local review. Review current evidence again.');}
   $transaction=NativeSourceTransaction::id();if(is_wp_error($transaction))return self::error('transaction','A durable local review transaction is required.');$database=$wpdb;
   $again=ReviewConfirmation::inspect($action,$id,$shop,$params,['confirmation_nonce'=>$body['df_confirmation_nonce']??null,'issued_at'=>(int)($body['df_confirmation_issued_at']??0)]);if(is_wp_error($again))return $again;
   if(!hash_equals($receipt['evidence_hash'],$again['evidence_hash']))return self::error('stale','Current source evidence changed during local confirmation.');
   $writeStarted=current_time('mysql',true);$repo=new Repository();
   if($action===self::BEGIN_REVIEW)$result=$repo->transition('order',$id,'REVIEW_REQUIRED');
   elseif($action===self::APPROVE)$result=$repo->transition('order',$id,'APPROVED');
   elseif($action===self::BEGIN_PLAN_REVIEW)$result=$repo->transition('plan',(int)$source['selected_plan']['id'],'REVIEW_REQUIRED');
   elseif($action===self::APPROVE_PLAN)$result=$repo->transition('plan',(int)$source['selected_plan']['id'],'APPROVED');
   elseif($action===self::PREPARE_INTENT)$result=$repo->createIntent(['order_id'=>$id,'fulfillment_plan_id'=>(int)$source['selected_plan']['id'],'intent_type'=>'PREPARE_FULFILLMENT','input_payload'=>[]],'portal-local-held-intent:'.$receipt['acknowledgement_hash']);
   else $result=$repo->createPlan(['order_id'=>$id,'plan_version'=>'local-'.substr($receipt['acknowledgement_hash'],0,58),'provider'=>$source['connection']['provider'],'provider_mapping_snapshot'=>$source['line_identity_snapshot'],'shipping_method_metadata'=>['method'=>$params['shipping_method'],'source'=>'unverified_local_review_assumptions','provider_quote_verified'=>false],'cost_snapshot_metadata'=>['base_production_cost'=>(float)$params['base_production_cost'],'shipping_estimate'=>(float)$params['shipping_estimate'],'currency'=>$source['order']['currency'],'source'=>'unverified_local_review_assumptions','provider_quote_verified'=>false,'actual_charge_verified'=>false,'pricing_approved'=>false]],'portal-local-draft:'.$receipt['acknowledgement_hash']);
   if(is_wp_error($result))return $result;
   if(in_array($action,self::PLAN_ACTIONS,true)){
    $writeEnded=current_time('mysql',true);$before=$source['selected_plan'];
    if($action===self::PREPARE_INTENT){
     if((int)$result['id']<1||(int)$result['order_id']!==$id||(int)$result['fulfillment_plan_id']!==(int)$before['id']||$result['fulfillment_plan_payload_hash']!==$before['payload_hash']||$result['environment']!==$source['order']['environment']||$result['state']!=='BLOCKED'||$result['intent_type']!=='PREPARE_FULFILLMENT'||(int)$result['created_by']!==$receipt['actor_id']||$result['input_payload']!=='[]'||$result['idempotency_key']!=='portal-local-held-intent:'.$receipt['acknowledgement_hash']||$result['created_at']<$writeStarted||$result['created_at']>$writeEnded||$result['updated_at']<$writeStarted||$result['updated_at']>$writeEnded)return self::error('intent_effect','The exact requested BLOCKED intent effect could not be confirmed.');
    }else{
     $allowed=$action===self::BEGIN_PLAN_REVIEW?['state','updated_at']:['state','updated_at','approved_by','approved_at'];$immutableBefore=array_diff_key($before,array_flip($allowed));$immutableAfter=array_diff_key($result,array_flip($allowed));
     if(Validator::canonicalJson($immutableBefore)!==Validator::canonicalJson($immutableAfter)||$result['state']!==($action===self::BEGIN_PLAN_REVIEW?'REVIEW_REQUIRED':'APPROVED')||$result['updated_at']<$writeStarted||$result['updated_at']>$writeEnded)return self::error('plan_effect','Immutable plan material changed outside the requested local review effect.');
     if($action===self::BEGIN_PLAN_REVIEW&&((int)$result['approved_by']!==0||!empty($result['approved_at'])))return self::error('plan_effect','Plan review entry cannot record an approval.');
     if($action===self::APPROVE_PLAN&&((int)$result['approved_by']!==$receipt['actor_id']||!is_string($result['approved_at'])||$result['approved_at']<$writeStarted||$result['approved_at']>$writeEnded))return self::error('plan_effect','Plan approval must record this exact current reviewer and approval time.');
    }
   }
   // Recheck actual actor/module admission and current dependencies after the business write.
   $final=(new HeldFulfillmentPreparation())->forOrder($shop,$id);if(is_wp_error($final))return $final;
   if(get_current_user_id()!==$receipt['actor_id'])return self::error('actor','The current reviewer changed during local review.');
   foreach(['lines','line_identity_snapshot','connection','personalization','renders','reconciliation',...($action===self::PREPARE_INTENT?[]:['intents'])]as $field)if(Validator::canonicalJson($source[$field])!==Validator::canonicalJson($final[$field]))return self::error('changed','Current order dependencies changed during local review.');
   if(($action===self::DRAFT||in_array($action,self::PLAN_ACTIONS,true))&&Validator::canonicalJson($source['order'])!==Validator::canonicalJson($final['order']))return self::error('changed','Current order changed during draft preparation.');
   if($action===self::APPROVE&&($final['order']['state']!=='APPROVED'||(int)$final['order']['approved_by']!==$receipt['actor_id']||empty($final['readiness']['ready'])))return self::error('changed','Local order approval no longer matches current evidence.');
   if($action===self::BEGIN_REVIEW&&($final['order']['state']!=='REVIEW_REQUIRED'||(int)$final['order']['approved_by']!==(int)$source['order']['approved_by']||$final['order']['approved_at']!==$source['order']['approved_at']))return self::error('changed','Local review entry changed unexpectedly.');
   if(in_array($action,self::PLAN_ACTIONS,true)){
    $expectedPlans=$source['plans'];if($action!==self::PREPARE_INTENT)foreach($expectedPlans as &$plan)if((int)$plan['id']===(int)$result['id'])$plan=$result;unset($plan);
    if(Validator::canonicalJson($expectedPlans)!==Validator::canonicalJson($final['plans']))return self::error('changed','Stored fulfillment plans changed during local review.');
    if($action===self::PREPARE_INTENT){$expectedIntents=$source['intents'];array_unshift($expectedIntents,$result);if($result['state']!=='BLOCKED'||$result['intent_type']!=='PREPARE_FULFILLMENT'||Validator::canonicalJson($expectedIntents)!==Validator::canonicalJson($final['intents']))return self::error('changed','The held preparation intent no longer matches this exact plan.');}
    $verifiedPlan=self::currentPlan($final,(int)$params['plan_id'],$action===self::BEGIN_PLAN_REVIEW?self::APPROVE_PLAN:self::PREPARE_INTENT,false);if(is_wp_error($verifiedPlan))return $verifiedPlan;
   }
   if(is_wp_error(NativeSourceTransaction::verify($database,$transaction)))return self::error('transaction','The local review transaction changed before commit. Inspect stored evidence before trying again.');
   if($wpdb->query('COMMIT')===false)return self::error('commit','Local review commit is uncertain. Inspect stored order evidence before trying again.');$committed=true;
   return ['result'=>$result,'message'=>match($action){self::BEGIN_REVIEW=>'Local order entered review. Separate order approval remains required.',self::APPROVE=>'Local order approved. Production release remains held.',self::BEGIN_PLAN_REVIEW=>'Local fulfillment plan entered review. Separate plan approval remains required.',self::APPROVE_PLAN=>'Local fulfillment plan approved. Production release remains held.',self::PREPARE_INTENT=>'BLOCKED local fulfillment preparation intent created. Production release remains held.',default=>'DRAFT fulfillment plan created. Separate plan review remains required.'},'release_hold'=>true,'external_execution_authorized'=>false];
  }finally{if(!$committed)$wpdb->query('ROLLBACK');}
 }
 public function handle():void {
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){$this->notice(self::error('post','POST required.'));return;}
  $result=$this->processBrowser(wp_unslash($_POST));
  if(is_wp_error($result)){$this->notice($result);return;}
  if(isset($result['preview'])){$this->renderPreview($result['preview']);return;}
  echo '<section class="df-panel"><p>'.esc_html($result['message']).'</p><p>External execution authority: NO. Adapter invoked: NO.</p><a class="df-button" href="'.esc_url(add_query_arg(['df_view'=>'orders','df_shop'=>sanitize_key(wp_unslash($_POST['shop_key'])),'df_order_id'=>(int)$_POST['order_id']],OperatorReturnUrl::resolve(isset($_POST['return_url'])?wp_unslash($_POST['return_url']):''))).'">Return to this order</a></section>';
 }
 public function renderForms(string $shop,array $source):void {
  $order=$source['order'];$checks=$source['readiness']['checks']??[];if(in_array($order['state'],['VALIDATED','REVIEW_REQUIRED'],true))unset($checks['order_approved'],$checks['human_approval']);if($checks===[]||in_array(false,$checks,true))return;
  $action=match($order['state']){'VALIDATED'=>self::BEGIN_REVIEW,'REVIEW_REQUIRED'=>self::APPROVE,'APPROVED'=>self::DRAFT,default=>null};if($action===null)return;
  echo '<h3>Local order review</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  $this->hidden(['action'=>$action,'phase'=>'preview','return_url'=>OperatorReturnUrl::current(),'shop_key'=>$shop,'order_id'=>(string)$order['id'],'_wpnonce'=>wp_create_nonce($action.':'.$order['id'])]);
  if($action===self::DRAFT){echo '<p>Shipping and costs are unverified local review assumptions. They do not establish a provider quote, debit, actual margin or pricing approval. Currency comes from the stored order: '.esc_html($order['currency']).'.</p><label>Local shipping method<input name="shipping_method" pattern="[a-z][a-z0-9_-]{0,63}" maxlength="64" value="standard" required></label><label>Assumed base production cost<input name="base_production_cost" type="number" min="0" max="99999999.9999" step="0.0001" required></label><label>Assumed shipping cost<input name="shipping_estimate" type="number" min="0" max="99999999.9999" step="0.0001" required></label>';}
  echo '<button class="df-button" type="submit">'.(match($action){self::BEGIN_REVIEW=>'Preview entry into local order review',self::APPROVE=>'Preview local order approval',default=>'Preview DRAFT fulfillment plan'}).'</button></form>';
  if($order['state']==='APPROVED')foreach($source['plans']as $plan){
   $next=match($plan['state']){'DRAFT'=>self::BEGIN_PLAN_REVIEW,'REVIEW_REQUIRED'=>self::APPROVE_PLAN,'APPROVED'=>self::PREPARE_INTENT,default=>null};if($next===null)continue;
   if($next===self::PREPARE_INTENT){$exists=false;foreach($source['intents']as $intent)if((int)$intent['fulfillment_plan_id']===(int)$plan['id']&&in_array($intent['intent_type'],['PREPARE_ORDER','PREPARE_FULFILLMENT'],true))$exists=true;if($exists)continue;}
   echo '<h4>Plan #'.esc_html((string)$plan['id']).' / '.esc_html($plan['state']).'</h4><p>Local plan review does not verify a provider quote, charge, visual render or production authorization. Preparation intents remain BLOCKED.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
   $this->hidden(['action'=>$next,'phase'=>'preview','return_url'=>OperatorReturnUrl::current(),'shop_key'=>$shop,'order_id'=>(string)$order['id'],'plan_id'=>(string)$plan['id'],'_wpnonce'=>wp_create_nonce($next.':'.$order['id'])]);
   echo '<button class="df-button" type="submit">'.esc_html(match($next){self::BEGIN_PLAN_REVIEW=>'Preview local fulfillment plan review entry',self::APPROVE_PLAN=>'Preview local fulfillment plan approval',default=>'Preview BLOCKED fulfillment preparation intent'}).'</button></form>';
  }

 }
 private function renderPreview(array $context):void {
  echo '<section class="df-panel"><h2>'.(match($context['action']){self::BEGIN_REVIEW=>'Confirm entry into local order review',self::APPROVE=>'Confirm local order approval',self::BEGIN_PLAN_REVIEW=>'Confirm local fulfillment plan review entry',self::APPROVE_PLAN=>'Confirm local fulfillment plan approval',self::PREPARE_INTENT=>'Confirm BLOCKED fulfillment preparation intent',default=>'Confirm DRAFT fulfillment plan'}).'</h2>';
  foreach($context['summary']as $summary)echo '<p>'.esc_html($summary).'</p>';
  echo '<p>'.esc_html($context['effect']).'</p><p>Evidence SHA-256 <code>'.esc_html($context['evidence_hash']).'</code></p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  $this->hidden(['action'=>$context['action'],'phase'=>'confirm','return_url'=>OperatorReturnUrl::resolve(isset($_POST['return_url'])?wp_unslash($_POST['return_url']):''),'shop_key'=>$context['shop'],'order_id'=>(string)$context['target_id'],'_wpnonce'=>wp_create_nonce($context['action'].':'.$context['target_id']),'df_confirmation_shop'=>$context['shop'],'df_confirmation_nonce'=>$context['confirmation_nonce'],'df_confirmation_issued_at'=>(string)$context['issued_at'],'df_confirmation_tokens[REVIEW]'=>$context['token']]+$context['parameters']);
  echo '<label><input type="checkbox" name="df_confirm_review" value="1" required>I reviewed this exact stored order, current evidence and local assumptions. Production remains held.</label><button class="df-button" type="submit">Confirm local action</button></form></section>';
 }
 /** Validate frozen local material, never reinterpret it as provider or production authority. */
 private static function currentPlan(array $source,int $planId,string $action,bool $denyExistingIntent=true):array|WP_Error {
  $plan=null;foreach($source['plans']as $row)if((int)$row['id']===$planId)$plan=$row;
  $expected=match($action){self::BEGIN_PLAN_REVIEW=>'DRAFT',self::APPROVE_PLAN=>'REVIEW_REQUIRED',default=>'APPROVED'};
  if(!is_array($plan)||$plan['state']!==$expected||(int)$plan['order_id']!==(int)$source['order']['id']||$plan['environment']!==$source['order']['environment']||$plan['provider']!==$source['connection']['provider'])return self::error('plan','Select the exact current '.$expected.' plan belonging to this order and reviewed connection.');
  if($expected==='DRAFT'&&((int)$plan['approved_by']!==0||!empty($plan['approved_at'])))return self::error('plan','A DRAFT plan cannot carry approval metadata.');
  if($expected==='APPROVED'&&((int)$plan['approved_by']<1||empty($plan['approved_at'])))return self::error('plan','Existing human plan approval is required.');
  if($action===self::PREPARE_INTENT&&$denyExistingIntent)foreach($source['intents']as $intent)if((int)$intent['fulfillment_plan_id']===$planId&&in_array($intent['intent_type'],['PREPARE_ORDER','PREPARE_FULFILLMENT'],true))return self::error('intent_exists','A local preparation intent already exists for this exact plan. Inspect its held evidence.');
  try {
   $payload=self::document($plan['canonical_payload']);
   if(!preg_match('/^[a-f0-9]{64}$/D',$plan['payload_hash'])||!hash_equals($plan['payload_hash'],hash('sha256',$plan['canonical_payload']))||(int)($payload['order_id']??0)!==(int)$source['order']['id']||($payload['provider']??null)!==$plan['provider'])throw new \InvalidArgumentException();
   foreach(['mapping'=>'provider_mapping_snapshot','print_area'=>'print_area_snapshot','shipping'=>'shipping_method_metadata','cost'=>'cost_snapshot_metadata','readiness'=>'readiness']as $field=>$column)if(!is_array($payload[$field]??null)||Validator::canonicalJson($payload[$field])!==$plan[$column])throw new \InvalidArgumentException();
   if(!is_array($payload['line_identity_snapshot']??null)||Validator::canonicalJson($payload['line_identity_snapshot'])!==Validator::canonicalJson($source['line_identity_snapshot'])||Validator::canonicalJson($payload['readiness'])!==Validator::canonicalJson($source['readiness'])||!hash_equals((string)$plan['readiness_hash'],(string)$source['readiness']['hash']))return self::error('plan_stale','Plan quantities, mapped material, ownership or readiness changed. Prepare a fresh DRAFT plan.');
   $personal=(new \DigiForge\Orders\PersonalizationCurrentness())->forPlan((int)$source['order']['id'],(int)$source['order']['personalization_required']===1);if(is_wp_error($personal))return $personal;
   $hash=Validator::hash($personal);$stored=self::document($plan['personalization_snapshot']);
   if(Validator::canonicalJson((array)($payload['personalization']??[]))!==Validator::canonicalJson($personal)||($payload['personalization_evidence_hash']??null)!==$hash||($stored['evidence_hash']??null)!==$hash||Validator::canonicalJson((array)($stored['submissions']??[]))!==Validator::canonicalJson($personal))return self::error('plan_stale','Plan buyer personalization changed. Prepare a fresh DRAFT plan.');
   if(isset($payload['cost']['currency'])&&$payload['cost']['currency']!==$source['order']['currency'])throw new \InvalidArgumentException();
   $evidence=\DigiForge\Orders\FulfillmentEvidence::validate($payload['shipping'],$payload['cost'],$source['order']['currency']);if(is_wp_error($evidence))return $evidence;
  }catch(\Throwable){return self::error('plan_snapshot','Stored canonical plan snapshots must match completely before local plan review.');}
  return $plan;
 }
 private static function document(string $json):array {if(strlen($json)>65536)throw new \InvalidArgumentException();$value=json_decode($json,true,32,JSON_THROW_ON_ERROR);if(!is_array($value)||Validator::canonicalJson($value)!==$json)throw new \InvalidArgumentException();return $value;}
 private function lockSource(int $id,array $source):void {
  global $wpdb;self::rows($wpdb->prepare('SELECT * FROM '.Tables::integrations().' WHERE id=%d FOR UPDATE',(int)$source['connection']['integration_id']));self::rows($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE id=%d FOR UPDATE',$id));self::rows($wpdb->prepare('SELECT * FROM '.Tables::order_line_items().' WHERE order_id=%d ORDER BY id LIMIT 101 FOR UPDATE',$id));
  foreach(['fulfillment_plans','fulfillment_intents','pod_render_evidence']as $table)self::rows($wpdb->prepare('SELECT * FROM '.Tables::{$table}().' WHERE order_id=%d ORDER BY id LIMIT 101 FOR UPDATE',$id));
  foreach($source['line_identity_snapshot']['items']as $item){
   self::rows($wpdb->prepare('SELECT * FROM '.Tables::pod_mappings().' WHERE id=%d FOR UPDATE',(int)$item['line']['provider_mapping_id']));
   self::rows($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE provider_mapping_id=%d ORDER BY id LIMIT 101 FOR UPDATE',(int)$item['line']['provider_mapping_id']));
   foreach($item['ownership']as $owner)foreach(['businesses'=>'business_id','stores'=>'store_id','product_programs'=>'product_program_id']as $table=>$column)self::rows($wpdb->prepare('SELECT * FROM '.Tables::{$table}().' WHERE id=%d FOR UPDATE',(int)$owner[$column]));
   self::rows($wpdb->prepare('SELECT * FROM '.Tables::personalization_submissions().' WHERE order_line_item_id=%d ORDER BY id LIMIT 101 FOR UPDATE',(int)$item['line']['id']));
  }
  foreach($source['personalization']as $row)self::rows($wpdb->prepare('SELECT * FROM '.Tables::personalization_schemas().' WHERE id=%d FOR UPDATE',(int)$row['personalization_schema_id']));
 }
 private static function rows(string $sql):array {global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($sql,ARRAY_A);if(!is_array($rows)||$wpdb->last_error!==''||count($rows)>100)throw new \RuntimeException('Current source unavailable.');return $rows;}
 private function hidden(array $fields):void {foreach($fields as $key=>$value)echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">';}
 private function notice(WP_Error $error):void {echo '<section class="df-panel"><p role="alert">'.esc_html($error->get_error_message()).'</p></section>';}
 private static function error(string $code,string $message):WP_Error {return new WP_Error('order_local_'.$code,$message,['status'=>409,'release_hold'=>true,'external_execution_authorized'=>false]);}
}
