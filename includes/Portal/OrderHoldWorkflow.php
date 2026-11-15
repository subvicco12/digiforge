<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Connector\Printify\NativeSourceTransaction;
use DigiForge\Database\Tables;
use DigiForge\Listings\EtsyStoredShopScope;
use DigiForge\Orders\{Lifecycle,Repository,Validator};
use DigiForge\Security\{Logger,ShopAccess};
use WP_Error;

/** A restrictive local order transition only; provider cancellation and resumption are absent. */
final class OrderHoldWorkflow
{
 public const ACTION='digiforge_portal_order_local_hold';
 public function register():void {add_action('admin_post_'.self::ACTION,[$this,'handle']);}
 public static function context(string $shop,int $orderId):array|WP_Error {
  $row=self::read($shop,$orderId);if(is_wp_error($row))return $row;
  if(!in_array($row['state'],['RECEIVED','VALIDATED','REVIEW_REQUIRED','APPROVED'],true)||!Lifecycle::can('order',$row['state'],'ON_HOLD'))return self::error('state','The exact stored order is no longer in a state that permits local ON_HOLD.');
  return ['shop_key'=>$shop,'actor_id'=>get_current_user_id(),'order'=>$row];
 }
 public function processBrowser(array $body):array|WP_Error {
  $id=self::id($body['order_id']??null);$shop=$body['shop_key']??null;
  if(($body['action']??null)!==self::ACTION||$id<1||$shop!=='personalized_pod')return self::error('target','One exact Shop 2 order and local hold action are required.');
  if(!is_string($body['_wpnonce']??null)||!wp_verify_nonce($body['_wpnonce'],self::ACTION.':'.$id))return self::error('nonce','Valid exact-order local hold nonce required.');
  if(($body['phase']??null)==='preview'){$preview=ReviewConfirmation::inspect(self::ACTION,$id,$shop);if(is_wp_error($preview))return $preview;$access=self::admit($shop,(int)$preview['actor_id']);return is_wp_error($access)?$access:['preview'=>$preview];}
  if(($body['phase']??null)!=='confirm')return self::error('phase','Preview the current exact order before confirming a local hold.');
  global $wpdb;
  try {
   $boundary=self::rows('SELECT @@session.in_transaction AS active,@@session.autocommit AS autocommit');if(count($boundary)!==1||(string)$boundary[0]['active']!=='0'||(string)$boundary[0]['autocommit']!=='1')return self::error('transaction','Local hold confirmation requires a clean database transaction boundary.');
   foreach([Tables::orders(),Tables::audit_log()]as $table){$engines=self::rows($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if(count($engines)!==1||strtoupper((string)$engines[0]['ENGINE'])!=='INNODB')return self::error('storage','Verified transactional order and audit storage is required for a local hold.');}
  }catch(\RuntimeException){return self::error('storage','Current order transaction evidence is unavailable.');}
  // Consumption is outside the business transaction; a failed effect cannot restore this acknowledgement.
  $receipt=ReviewConfirmation::accept(self::ACTION,$id,$body);if(is_wp_error($receipt))return $receipt;
  $started=false;$attempted=false;$changed=false;$committed=false;$commitState='NOT_ATTEMPTED';
  try {
   $wpdb->last_error='';if($wpdb->query('START TRANSACTION')===false||$wpdb->last_error!=='')throw new \RuntimeException('The local hold transaction could not start.');$started=true;
   $before=self::read($shop,$id,true);self::requireValue($before);
   $transaction=NativeSourceTransaction::id();if(is_wp_error($transaction))throw new \RuntimeException('A durable local hold transaction is required.');$database=$wpdb;
   $proof=ReviewConfirmation::inspect(self::ACTION,$id,$shop,[],['confirmation_nonce'=>$body['df_confirmation_nonce']??null,'issued_at'=>self::id($body['df_confirmation_issued_at']??null)]);self::requireValue($proof);
   $context=['shop_key'=>$shop,'actor_id'=>$receipt['actor_id'],'order'=>$before];
   if(!hash_equals($receipt['evidence_hash'],$proof['evidence_hash'])||!is_string($proof['order_hold_context_hash']??null)||!hash_equals($proof['order_hold_context_hash'],Validator::hash($context)))throw new \RuntimeException('The exact stored order or reviewer changed after local hold confirmation.');
   $writeStarted=current_time('mysql',true);$attempted=true;$result=(new Repository())->transition('order',$id,'ON_HOLD');self::requireValue($result);$changed=true;
   $after=self::read($shop,$id,true);self::requireValue($after);
   $allowed=array_flip(['state','updated_at']);if($after['state']!=='ON_HOLD'||$result!==$after||Validator::canonicalJson(array_diff_key($before,$allowed))!==Validator::canonicalJson(array_diff_key($after,$allowed))||$after['updated_at']<$writeStarted||$after['updated_at']>current_time('mysql',true))throw new \RuntimeException('Stored order changes exceeded the exact local ON_HOLD effect.');
   // Optional diagnostics must never undo a confirmed restriction just because child evidence is unavailable.
   $readiness='UNAVAILABLE';try{$projection=(new Repository())->readinessSnapshot($id);if(is_array($projection)){$readiness=empty($projection['ready'])?'BLOCKED':'UNAVAILABLE';}}catch(\Throwable){}
   $audit=['order_id'=>$id,'from'=>$before['state'],'to'=>'ON_HOLD','source_hash'=>Validator::hash($before),'acknowledgement_hash'=>$receipt['acknowledgement_hash'],'readiness_state'=>$readiness,'production_ready'=>false,'provider_cancellation_performed'=>false,'external_execution_authorized'=>false,'external_execution_performed'=>false];
   if(!Logger::write('portal_order_held',$audit,'order',(string)$id))throw new \RuntimeException('The local hold audit could not be durably recorded.');
   $stored=self::rows($wpdb->prepare("SELECT actor_id,context FROM ".Tables::audit_log()." WHERE event_type='portal_order_held' AND object_type='order' AND object_id=%s ORDER BY id DESC LIMIT 1",(string)$id));if(count($stored)!==1||(int)$stored[0]['actor_id']!==$receipt['actor_id']||json_decode($stored[0]['context'],true)!==$audit)throw new \RuntimeException('The exact local hold audit could not be confirmed.');
   $final=self::read($shop,$id,true);self::requireValue($final);if($final!==$after)throw new \RuntimeException('The stored order changed during final local hold inspection.');
   if(is_wp_error(NativeSourceTransaction::verify($database,$transaction)))throw new \RuntimeException('The local hold transaction changed before commit.');
   self::requireValue(self::admit($shop,$receipt['actor_id']));
   if(is_wp_error(NativeSourceTransaction::verify($database,$transaction)))throw new \RuntimeException('The local hold transaction changed during final admission.');
   $wpdb->last_error='';$commitState='UNKNOWN';if($wpdb->query('COMMIT')===false||$wpdb->last_error!=='')throw new \RuntimeException('The local hold commit acknowledgement was lost.');$commitState='COMMITTED';$committed=true;
   self::requireValue(self::admit($shop,$receipt['actor_id']));
   return ['order_id'=>$id,'shop_key'=>$shop,'state'=>'ON_HOLD','readiness_state'=>$readiness,'production_ready'=>false,'local_commit_state'=>'COMMITTED','local_metadata_committed'=>true,'local_metadata_changed'=>true,'read_only'=>false,'release_hold'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false,'external_execution_performed'=>false];
  }catch(\Throwable $failure){
   if($started&&!$committed){$wpdb->last_error='';$active=$wpdb->get_var('SELECT @@session.in_transaction');if($wpdb->last_error!==''||(string)$active!=='1'){if($attempted)$commitState='UNKNOWN';}else{$wpdb->last_error='';if($wpdb->query('ROLLBACK')===false||$wpdb->last_error!==''){if($attempted)$commitState='UNKNOWN';}}}
   $message=$commitState==='UNKNOWN'?'Local hold outcome is UNKNOWN. Inspect the current stored order; do not retry automatically.':($committed?'The local hold committed, but current response access is unavailable. An authorized operator must inspect it.':$failure->getMessage());
   return self::error('effect',$message,$commitState,$committed,$attempted||$changed);
  }
 }
 public function handle():void {
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){$this->notice(self::error('method','POST required.'));return;}$body=wp_unslash($_POST);$result=$this->processBrowser($body);if(is_wp_error($result)){$this->notice($result);return;}if(isset($result['preview'])){$this->preview($result['preview'],OperatorReturnUrl::resolve($body['return_url']??''));return;}
  echo '<section class="df-panel"><p>Order placed ON_HOLD locally.</p><p>Readiness: '.esc_html($result['readiness_state']).'. Production ready: NO.</p><p>Provider cancellation: NOT PERFORMED. Existing approvals, plans, intents and packages remain historical records. No resume, requeue or provider execution is authorized.</p><a class="df-button" href="'.esc_url(add_query_arg(['df_view'=>'orders','df_shop'=>$result['shop_key'],'df_order_id'=>$result['order_id']],OperatorReturnUrl::resolve($body['return_url']??''))).'">Inspect this order</a></section>';
 }
 public function render(string $shop,int $orderId):void {if($shop!=='personalized_pod')return;$actor=get_current_user_id();$context=self::context($shop,$orderId);if(is_wp_error($context))return;$access=self::admit($shop,$actor);if(is_wp_error($access))return;echo '<section class="df-panel"><h3>Restrict this local order</h3><p>Place only this stored order ON_HOLD. This local restriction does not cancel provider work or erase prior approvals.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';$this->hidden(['action'=>self::ACTION,'order_id'=>$orderId,'shop_key'=>$shop,'phase'=>'preview','_wpnonce'=>wp_create_nonce(self::ACTION.':'.$orderId),'return_url'=>OperatorReturnUrl::current()]);echo '<button class="df-button" type="submit">Preview local order hold</button></form></section>';}
 private function preview(array $p,string $return):void {echo '<section class="df-panel"><h2>Confirm local order hold</h2>';foreach($p['summary']as $line)echo '<p>'.esc_html($line).'</p>';echo '<p>'.esc_html($p['effect']).'</p><p>Current full order fingerprint <code>'.esc_html($p['evidence_hash']).'</code></p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';$this->hidden(['action'=>self::ACTION,'order_id'=>$p['target_id'],'shop_key'=>$p['shop'],'phase'=>'confirm','_wpnonce'=>wp_create_nonce(self::ACTION.':'.$p['target_id']),'return_url'=>$return,'df_confirmation_shop'=>$p['shop'],'df_confirmation_nonce'=>$p['confirmation_nonce'],'df_confirmation_issued_at'=>$p['issued_at'],'df_confirmation_tokens[REVIEW]'=>$p['token']]);echo '<label><input type="checkbox" name="df_confirm_review" value="1" required>I reviewed this exact stored order and request only its local ON_HOLD restriction. Provider cancellation is not performed.</label><button class="df-button" type="submit">Confirm local order hold</button></form></section>';}
 private static function read(string $shop,int $id,bool $lock=false):array|WP_Error {$actor=get_current_user_id();$access=self::admit($shop,$actor);if(is_wp_error($access))return $access;if($id<1)return self::error('target','An exact stored order is required.');global $wpdb;try{$rows=self::rows($wpdb->prepare("SELECT o.* FROM ".Tables::orders()." o WHERE o.id=%d AND BINARY o.channel=BINARY 'etsy' AND ".EtsyStoredShopScope::orderPredicate($shop,'o').($lock?' FOR UPDATE':''),$id));}catch(\RuntimeException){return self::error('source','Current stored order evidence is unavailable.');}$access=self::admit($shop,$actor);if(is_wp_error($access))return $access;return count($rows)===1?$rows[0]:self::error('scope','The exact stored order is unavailable in this shop.');}
 private static function admit(string $shop,int $actor):true|WP_Error {if($shop!=='personalized_pod'||$actor<1||get_current_user_id()!==$actor||!is_user_logged_in())return self::error('actor','A current authenticated Shop 2 operator is required.');foreach(['manage_digiforge_orders','manage_digiforge_pod']as $cap){$access=ShopAccess::authorize($shop,$cap);if(is_wp_error($access))return $access;}return get_current_user_id()===$actor?true:self::error('actor','The current local hold reviewer changed.');}
 private static function rows(string $sql):array {global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($sql,ARRAY_A);if(!is_array($rows)||$wpdb->last_error!=='')throw new \RuntimeException('Current local order evidence is unavailable.');return $rows;}
 private static function requireValue(mixed $value):void {if(is_wp_error($value))throw new \RuntimeException($value->get_error_message());}
 private static function id(mixed $raw):int {return ((is_int($raw)&&$raw>0&&$raw<=2147483647)||(is_string($raw)&&preg_match('/^[1-9][0-9]{0,9}$/D',$raw)===1&&(int)$raw<=2147483647))?(int)$raw:0;}
 private function hidden(array $fields):void {foreach($fields as $key=>$value)echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">';}
 private function notice(WP_Error $error):void {echo '<section class="df-panel"><p role="alert">'.esc_html($error->get_error_message()).'</p></section>';}
 private static function error(string $code,string $message,string $commit='NOT_ATTEMPTED',bool $committed=false,bool $attempted=false):WP_Error {return new WP_Error('order_hold_'.$code,$message,['status'=>409,'local_commit_state'=>$commit,'local_metadata_committed'=>$commit==='UNKNOWN'?null:$committed,'local_metadata_changed'=>$commit==='UNKNOWN'&&$attempted?null:($committed&&$attempted),'persisted_effect_possible'=>$attempted,'read_only'=>!$attempted,'production_ready'=>false,'release_hold'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false,'external_execution_performed'=>false]);}
}
