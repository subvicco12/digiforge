<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Orders\HeldFulfillmentPreparation;
use DigiForge\Orders\Validator;
use DigiForge\Queue\Shop2HeldJobRecovery;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** Explicit local metadata and expired-lease quarantine, never dispatch or retry authority. */
final class OrderHeldJobWorkflow
{
 public const ENQUEUE='digiforge_portal_order_held_job_enqueue';
 public const QUARANTINE='digiforge_portal_order_held_job_quarantine';
 public function register():void {foreach([self::ENQUEUE,self::QUARANTINE]as $action)add_action('admin_post_'.$action,[$this,'handle']);}
 public static function parameters(string $action,array $body):array {$params=['intent_id'=>self::id($body['intent_id']??null)];if($action===self::QUARANTINE)$params['job_id']=self::id($body['job_id']??null);return $params;}
 /** Server-only evidence for purpose-bound one-use confirmation; caller hashes grant nothing. */
 public static function context(string $action,int $orderId,string $shop,array $params):array|WP_Error {
  if(!in_array($action,[self::ENQUEUE,self::QUARANTINE],true)||$shop!=='personalized_pod'||$orderId<1||self::id($params['intent_id']??null)<1||($action===self::QUARANTINE&&self::id($params['job_id']??null)<1))return self::error('target','Exact Shop 2 order, intent and optional held-job identities are required.');
  $intentId=(int)$params['intent_id'];$service=new Shop2HeldJobRecovery();
  if($action===self::QUARANTINE){
   $snapshot=$service->inspect($shop,$orderId,$intentId,(int)$params['job_id']);if(is_wp_error($snapshot))return $snapshot;
   if(($snapshot['quarantine_eligible']??false)!==true)return self::error('lease','Only an exact expired RUNNING held-job lease may move to HUMAN_REVIEW.');
   $access=self::access($shop,get_current_user_id());if(is_wp_error($access))return $access;
   return ['shop_key'=>$shop,'order_id'=>$orderId,'intent_id'=>$intentId,'snapshot'=>$snapshot];
  }
  $existing=$service->forIntent($shop,$orderId,$intentId);if(is_wp_error($existing))return $existing;
  if(($existing['items']??[])!==[])return self::error('existing','Existing held-job metadata must be inspected. This workflow does not requeue or retry jobs.');
  $held=new HeldFulfillmentPreparation();$source=$held->forOrder($shop,$orderId);if(is_wp_error($source))return $source;
  $prepared=$held->prepare($shop,$orderId,$intentId);if(is_wp_error($prepared))return $prepared;
  if(($prepared['state']??null)!=='HELD_FULFILLMENT_PREPARED'||($prepared['release_hold']??false)!==true||($prepared['external_execution_authorized']??true)!==false||($prepared['external_execution_performed']??true)!==false||($prepared['preparation']['adapter_invoked']??true)!==false||!is_string($prepared['current_evidence_hash']??null)||!hash_equals(Validator::hash($source),$prepared['current_evidence_hash']))return self::error('changed','Current held preparation changed while its metadata was being inspected.');
  $access=self::access($shop,get_current_user_id());if(is_wp_error($access))return $access;
  return ['shop_key'=>$shop,'order_id'=>$orderId,'intent_id'=>$intentId,'source'=>$source,'preparation'=>$prepared,'existing_jobs'=>$existing];
 }
 public static function summary(string $action,array $context):array {
  $summary=['Order #'.$context['order_id'].' / intent #'.$context['intent_id'],'Shop '.$context['shop_key'].' / current reviewer #'.get_current_user_id()];
  if($action===self::ENQUEUE){$p=$context['preparation'];$summary[]='Approved plan #'.$p['fulfillment_plan_id'].' / SHA-256 '.$p['plan_payload_hash'];$summary[]='Current source evidence SHA-256 '.$p['current_evidence_hash'];$summary[]='Initial job state: BLOCKED. No worker claim, dispatch or retry is requested.';}
  else{$s=$context['snapshot'];$j=$s['job'];$summary[]='Held job #'.$s['job_id'].' / '.$j['state'];$summary[]='Payload SHA-256 '.$j['payload_hash'];$summary[]='Lease owner fingerprint '.(string)($j['owner_hash']??'UNAVAILABLE');$summary[]='Lease expired at '.(string)$j['lease_expires_at'];$summary[]='Local effect: expired RUNNING lease to HUMAN_REVIEW. Previous worker outcome remains unresolved.';$summary[]='Exact lease snapshot SHA-256 '.$s['snapshot_hash'];}
  $summary[]='Production release remains held. No provider call, scheduler activation, requeue or automatic retry is authorized.';return $summary;
 }
 public function processBrowser(array $body):array|WP_Error {
  $action=$body['action']??null;$orderId=self::id($body['order_id']??null);$shop=$body['shop_key']??null;
  if(!is_string($action)||!in_array($action,[self::ENQUEUE,self::QUARANTINE],true)||$orderId<1||!is_string($shop)||$shop!=='personalized_pod')return self::error('target','One exact supported held-job action and Shop 2 order are required.');
  $params=self::parameters($action,$body);if($params['intent_id']<1||($action===self::QUARANTINE&&$params['job_id']<1))return self::error('target','Exact stored intent and held-job identities are required.');
  $nonce=self::nonceAction($action,$orderId,$params['intent_id'],$params['job_id']??0);
  if(!is_string($body['_wpnonce']??null)||!wp_verify_nonce($body['_wpnonce'],$nonce))return self::error('nonce','Valid exact order/intent/job action nonce required.');
  if(($body['phase']??null)==='preview'){$preview=ReviewConfirmation::inspect($action,$orderId,$shop,$params);if(is_wp_error($preview))return $preview;$access=self::access($shop,(int)$preview['actor_id']);return is_wp_error($access)?$access:['preview'=>$preview];}
  if(($body['phase']??null)!=='confirm')return self::error('phase','Preview the exact current held-job evidence before confirming.');
  // Acknowledgement is durable outside the service's transaction, even when the local effect fails.
  global $wpdb;$wpdb->last_error='';$boundary=$wpdb->get_row('SELECT @@session.in_transaction AS active,@@session.autocommit AS autocommit',ARRAY_A);
  if($wpdb->last_error!==''||!is_array($boundary)||(string)($boundary['active']??'')!=='0'||(string)($boundary['autocommit']??'')!=='1')return self::error('transaction','Held-job confirmation requires a clean database transaction boundary.');
  $receipt=ReviewConfirmation::accept($action,$orderId,$body);if(is_wp_error($receipt))return $receipt;
  $context=self::context($action,$orderId,$shop,$params);if(is_wp_error($context))return $context;
  $again=ReviewConfirmation::inspect($action,$orderId,$shop,$params,['confirmation_nonce'=>$body['df_confirmation_nonce']??null,'issued_at'=>self::id($body['df_confirmation_issued_at']??null)]);if(is_wp_error($again))return $again;
  if(!hash_equals($receipt['evidence_hash'],$again['evidence_hash'])||!is_string($again['held_job_context_hash']??null)||!hash_equals($again['held_job_context_hash'],Validator::hash($context))||get_current_user_id()!==$receipt['actor_id'])return self::error('changed','The scoped held-job evidence or current reviewer changed after confirmation.');
  $service=new Shop2HeldJobRecovery();
  if($action===self::ENQUEUE){$p=$context['preparation'];$key=Shop2HeldJobRecovery::enqueueKey($shop,$orderId,$params['intent_id'],$p['plan_payload_hash'],$p['current_evidence_hash'],$receipt['actor_id']);$result=$service->enqueue($shop,$orderId,$params['intent_id'],$p['current_evidence_hash'],$key);}
  else $result=$service->quarantine($shop,$orderId,$params['intent_id'],$params['job_id'],$context['snapshot']['snapshot_hash']);
  if(is_wp_error($result))return $result;
  $expected=$action===self::ENQUEUE?'BLOCKED':'HUMAN_REVIEW';
  if(($result['shop_key']??null)!==$shop||(int)($result['order_id']??0)!==$orderId||(int)($result['intent_id']??0)!==$params['intent_id']||(int)($result['job_id']??0)<1||(int)($result['job']['id']??0)!==(int)$result['job_id']||($result['job']['state']??null)!==$expected||($result['release_hold']??false)!==true||($result['retry_permitted']??true)!==false||($result['replay_permitted']??true)!==false||($result['external_execution_authorized']??true)!==false||($result['external_execution_performed']??true)!==false)return self::error('outcome','The requested held-job effect could not be confirmed. Inspect current stored evidence; do not retry automatically.');
  if(!array_key_exists('locked_at',$result['job'])||!array_key_exists('lease_expires_at',$result['job'])||$result['job']['locked_at']!==null||$result['job']['lease_expires_at']!==null||($action===self::ENQUEUE&&(int)$result['job']['attempts']!==0)||($action===self::QUARANTINE&&((int)$result['job_id']!==$params['job_id']||(int)$result['job']['attempts']!==(int)$context['snapshot']['job']['attempts']||$result['job']['payload_hash']!==$context['snapshot']['job']['payload_hash']||$result['job']['last_error']!=='LEASE_EXPIRED')))return self::error('outcome','Unexpected held-job lease or identity changes require manual inspection.');
  $access=self::access($shop,$receipt['actor_id']);if(is_wp_error($access))return $access;
  return ['snapshot'=>$result,'message'=>$action===self::ENQUEUE?'BLOCKED held-job metadata recorded. No worker dispatch is authorized.':'Expired held job moved to HUMAN_REVIEW. Its previous worker outcome still requires review.'];
 }
 public function handle():void {
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){$this->notice(self::error('method','POST required.'));return;}
  $body=wp_unslash($_POST);$result=$this->processBrowser($body);if(is_wp_error($result)){$this->notice($result);return;}
  if(isset($result['preview'])){$this->renderPreview($result['preview'],OperatorReturnUrl::resolve($body['return_url']??''));return;}
  $s=$result['snapshot'];echo '<section class="df-panel"><p>'.esc_html($result['message']).'</p><p>Release hold: YES. External execution authority: NO. Adapter invoked: NO.</p><a class="df-button" href="'.esc_url(add_query_arg(['df_view'=>'orders','df_shop'=>$s['shop_key'],'df_order_id'=>$s['order_id'],'df_intent_id'=>$s['intent_id'],'df_held_job_id'=>$s['job_id']],OperatorReturnUrl::resolve($body['return_url']??''))).'">Inspect this order’s held-job metadata</a></section>';
 }
 /** Read-only scoped navigation; every local write requires the POST preview/confirmation above. */
 public function render(string $shop,int $orderId,int $intentId):void {
  $actor=get_current_user_id();$service=new Shop2HeldJobRecovery();$list=$service->forIntent($shop,$orderId,$intentId);if(is_wp_error($list)){$this->notice($list);return;}
  $enqueue=null;if($list['items']===[])$enqueue=self::context(self::ENQUEUE,$orderId,$shop,['intent_id'=>$intentId]);
  $selected=null;if(isset($_GET['df_held_job_id'])){$jobId=self::id($_GET['df_held_job_id']);$selected=$jobId>0?$service->inspect($shop,$orderId,$intentId,$jobId):self::error('target','One exact stored held-job ID is required.');}
  $access=self::access($shop,$actor);if(is_wp_error($access)){$this->notice($access);return;}
  // No private metadata is emitted until every source read and final admission succeeds.
  echo '<section class="df-panel"><h3>Order-specific held-job metadata</h3><p>These records describe local held preparation only. Enqueueing keeps the job BLOCKED. Expired-lease quarantine records HUMAN_REVIEW and grants no retry or dispatch permission.</p>';
  if($enqueue!==null){if(is_wp_error($enqueue))echo '<p role="alert">'.esc_html($enqueue->get_error_message()).'</p>';else $this->form(self::ENQUEUE,$shop,$orderId,$intentId,0,OperatorReturnUrl::current(),'Preview BLOCKED held-job metadata');}
  foreach($list['items']as $snapshot){$this->job($snapshot);echo '<p><a class="df-button df-button-compact" href="'.esc_url(add_query_arg(['df_view'=>'orders','df_shop'=>$shop,'df_order_id'=>$orderId,'df_intent_id'=>$intentId,'df_held_job_id'=>$snapshot['job_id']],OperatorReturnUrl::current())).'">Inspect held job #'.esc_html((string)$snapshot['job_id']).'</a></p>';}
  if($selected!==null){if(is_wp_error($selected))echo '<p role="alert">'.esc_html($selected->get_error_message()).'</p>';else{$this->job($selected);if($selected['quarantine_eligible']===true)$this->form(self::QUARANTINE,$shop,$orderId,$intentId,(int)$selected['job_id'],OperatorReturnUrl::current(),'Preview expired held-job quarantine');}}
  echo '</section>';
 }
 private function renderPreview(array $preview,string $return):void {
  echo '<section class="df-panel"><h2>'.($preview['action']===self::ENQUEUE?'Confirm BLOCKED held-job metadata':'Confirm expired held-job quarantine').'</h2>';foreach($preview['summary']as $line)echo '<p>'.esc_html($line).'</p>';echo '<p>'.esc_html($preview['effect']).'</p><p>Current evidence SHA-256 <code>'.esc_html($preview['evidence_hash']).'</code></p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  $p=$preview['parameters'];$this->hidden(['action'=>$preview['action'],'phase'=>'confirm','shop_key'=>$preview['shop'],'order_id'=>$preview['target_id'],'return_url'=>$return,'_wpnonce'=>wp_create_nonce(self::nonceAction($preview['action'],$preview['target_id'],$p['intent_id'],$p['job_id']??0)),'df_confirmation_shop'=>$preview['shop'],'df_confirmation_nonce'=>$preview['confirmation_nonce'],'df_confirmation_issued_at'=>$preview['issued_at'],'df_confirmation_tokens[REVIEW]'=>$preview['token']]+$p);
  echo '<label><input type="checkbox" name="df_confirm_review" value="1" required>I reviewed this exact stored order, intent and held-job evidence. This is a local metadata effect only; production remains held.</label><button class="df-button" type="submit">Confirm local held-job action</button></form></section>';
 }
 private function form(string $action,string $shop,int $order,int $intent,int $job,string $return,string $label):void {echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';$fields=['action'=>$action,'phase'=>'preview','shop_key'=>$shop,'order_id'=>$order,'intent_id'=>$intent,'return_url'=>$return,'_wpnonce'=>wp_create_nonce(self::nonceAction($action,$order,$intent,$job))];if($job>0)$fields['job_id']=$job;$this->hidden($fields);echo '<button class="df-button" type="submit">'.esc_html($label).'</button></form>';}
 private function job(array $snapshot):void {$j=$snapshot['job'];echo '<h4>Held job #'.esc_html((string)$snapshot['job_id']).' / '.esc_html($j['state']).'</h4><p>Attempts '.esc_html((string)$j['attempts']).' / '.esc_html((string)$j['max_attempts']).'. Lease expiry '.esc_html((string)($j['lease_expires_at']??'NONE')).'.</p><p>Payload fingerprint <code>'.esc_html($j['payload_hash']).'</code></p><p>Snapshot fingerprint <code>'.esc_html($snapshot['snapshot_hash']).'</code></p><p>Expired-lease quarantine eligible: '.($snapshot['quarantine_eligible']?'YES':'NO').'. Retry permitted: NO. Dispatch authority: NO.</p>';}
 private static function access(string $shop,int $actor):true|WP_Error {if($shop!=='personalized_pod'||$actor<1||get_current_user_id()!==$actor)return self::error('actor','The current held-job reviewer changed.');foreach(['manage_digiforge_automation','manage_digiforge_orders','manage_digiforge_pod']as $cap){$access=ShopAccess::authorize($shop,$cap);if(is_wp_error($access))return $access;}return get_current_user_id()===$actor?true:self::error('actor','The current held-job reviewer changed.');}
 private static function nonceAction(string $action,int $order,int $intent,int $job=0):string {return $action.':'.$order.':'.$intent.($job>0?':'.$job:'');}
 private static function id(mixed $raw):int {return ((is_int($raw)&&$raw>0&&$raw<=2147483647)||(is_string($raw)&&preg_match('/^[1-9][0-9]{0,9}$/D',$raw)===1&&(int)$raw<=2147483647))?(int)$raw:0;}
 private function hidden(array $fields):void {foreach($fields as $key=>$value)echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">';}
 private function notice(WP_Error $error):void {echo '<section class="df-panel"><p role="alert">'.esc_html($error->get_error_message()).'</p></section>';}
 private static function error(string $code,string $message):WP_Error {return new WP_Error('order_held_job_'.$code,$message,['status'=>409,'release_hold'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false,'external_execution_performed'=>false]);}
}
