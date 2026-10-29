<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use WP_Error;

/** One-use dispatch receipt backed by the existing consumed permit, options and immutable audit. */
final class PrintifyDispatchClaim
{
 public static function claim(array $permit,array $request):true|WP_Error {
  $hash=$permit['authorization_hash']??null;if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))return self::error();
  global $wpdb;$lock='df-printify-dispatch-'.substr($hash,0,42);$wpdb->last_error='';
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1'||self::failed())return self::error();
  $transaction=false;
  try{
   $valid=self::verifyPermit($permit,$request);if(is_wp_error($valid))return $valid;
   $scope=$request['connection_scope'];
   foreach([$wpdb->options,Tables::audit_log()]as $table){$wpdb->last_error='';$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if(self::failed()||strtoupper((string)$engine)!=='INNODB')return self::error();}
   // Do not commit a caller's transaction or dispatch before a durable claim.
   $wpdb->last_error='';$active=$wpdb->get_var('SELECT @@session.in_transaction');if(self::failed()||(string)$active!=='0')return self::error();
   $name='df_printify_dispatch_'.$hash;$wpdb->last_error='';$existing=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$name));if(self::failed()||$existing!==null)return self::error();
   $wpdb->last_error='';$anchors=$wpdb->get_results($wpdb->prepare('SELECT id FROM '.Tables::audit_log().' WHERE object_type=%s AND object_id=%s LIMIT 1','printify_dispatch',$hash),ARRAY_A);if(self::failed()||!is_array($anchors)||$anchors!==[])return self::error();
   if($wpdb->query('START TRANSACTION')===false)return self::error();$transaction=true;
   $record=['authorization_hash'=>$hash,'request_fingerprint'=>$request['request_fingerprint'],'binding_hash'=>$scope['binding_hash'],'action'=>$request['action'],'actor_id'=>get_current_user_id(),'recorded_at'=>gmdate('c')];
   if($wpdb->insert($wpdb->options,['option_name'=>$name,'option_value'=>wp_json_encode($record),'autoload'=>'no'])!==1||!Logger::write('printify_dispatch_claimed',$record,'printify_dispatch',$hash)){$wpdb->query('ROLLBACK');return self::error();}
   if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return self::error();}
   $transaction=false;return true;
  }catch(\Throwable){if($transaction)$wpdb->query('ROLLBACK');return self::error();}finally{$wpdb->last_error='';$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));if((string)$released!=='1'||self::failed())return self::error();}
 }
 public static function verifyPermit(array $permit,array $request):true|WP_Error {
  $hash=$permit['authorization_hash']??null;if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)||!is_array($permit['authorization']??null))return self::error();
  global $wpdb;
   $scope=$request['connection_scope'];$wpdb->last_error='';
   $rows=$wpdb->get_results($wpdb->prepare('SELECT n.nonce_hash,n.consumed_by,b.package_hash,p.package_hash AS current_hash,p.order_id,p.readiness_hash,p.provider_mapping_id,o.business_id,o.store_id,o.product_program_id,m.environment,m.provider FROM '.Tables::pod_execution_nonces().' n INNER JOIN '.Tables::pod_authorization_bindings().' b ON b.nonce_hash=n.nonce_hash AND b.authorization_hash=n.authorization_hash AND b.bound_by=n.consumed_by INNER JOIN '.Tables::pod_authorization_packages().' p ON p.id=b.package_id INNER JOIN '.Tables::pod_business_mappings().' o ON o.id=p.ownership_mapping_id AND o.provider_mapping_id=p.provider_mapping_id INNER JOIN '.Tables::pod_mappings().' m ON m.id=o.provider_mapping_id AND m.product_version_id=o.product_version_id WHERE n.authorization_hash=%s AND p.state=\'APPROVED_PACKAGE\' AND p.approved_by>0 AND p.approved_at IS NOT NULL AND o.state=\'APPROVED\' AND o.approved_by>0 AND o.approved_at IS NOT NULL AND m.state=\'APPROVED\' AND m.approved_by>0 AND m.approved_at IS NOT NULL LIMIT 2',$hash),ARRAY_A);
   if(self::failed()||!is_array($rows)||count($rows)!==1)return self::error();$row=$rows[0];
   if((int)$row['consumed_by']!==get_current_user_id()||$row['package_hash']!==$row['current_hash']||$row['provider']!=='printify'||$row['environment']!==$scope['environment'])return self::error();
   foreach(['business_id'=>'business_id','store_id'=>'store_id','program_id'=>'product_program_id']as $field=>$column)if($scope[$field] !==(int)$row[$column])return self::error();
  $record=['state'=>'EXECUTION_AUTHORIZED','executed'=>false,'authorization'=>$permit['authorization'],'authorization_hash'=>$hash];
  $verified=ExecutionAuthorizationVerifier::verify($record,$request['action'],(string)($permit['evidence_hash']??''),time(),static fn(string $nonce):bool=>hash_equals($row['nonce_hash'],hash('sha256',$nonce)),$request['request_fingerprint']);
  if(is_wp_error($verified))return $verified;
  // Consumed human authority cannot outlive cancellation, changed lineage or readiness.
  $orderScope=\DigiForge\Listings\EtsyStoredShopScope::orderPredicate($scope['shop_key'],'dispatch_order');$wpdb->last_error='';
  $current=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::orders()." dispatch_order WHERE dispatch_order.id=%d AND BINARY dispatch_order.environment=BINARY %s AND dispatch_order.state='APPROVED' AND dispatch_order.approved_by>0 AND dispatch_order.approved_at IS NOT NULL AND ".$orderScope." AND EXISTS (SELECT 1 FROM ".Tables::order_line_items()." dispatch_line WHERE dispatch_line.order_id=dispatch_order.id AND dispatch_line.provider_mapping_id=%d AND BINARY dispatch_line.environment=BINARY dispatch_order.environment)",(int)$row['order_id'],$scope['environment'],(int)$row['provider_mapping_id']));
  if(self::failed()||(string)$current!=='1')return self::orderChanged();
  $readiness=(new \DigiForge\Orders\Repository())->readiness((int)$row['order_id']);
  if(is_wp_error($readiness)||empty($readiness['ready'])||!hash_equals((string)$row['readiness_hash'],hash('sha256',(string)wp_json_encode($readiness))))return self::orderChanged();
  return true;
 }
 /** @phpstan-impure */
 private static function failed():bool {global $wpdb;return $wpdb->last_error!=='';}
 private static function orderChanged():WP_Error {return new WP_Error('printify_dispatch_order_changed','Current approved order, shop and immutable readiness evidence are required.',['status'=>409,'retry_permitted'=>false,'network_request_attempted'=>false]);}
 private static function error():WP_Error {return new WP_Error('printify_dispatch_evidence_required','A unique durable consumed permit and unused dispatch receipt are required.',['status'=>409,'retry_permitted'=>false,'network_request_attempted'=>false]);}
}
