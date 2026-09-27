<?php
declare(strict_types=1);
namespace DigiForge\AI;
use DigiForge\Database\Tables;
use WP_Error;

/** Shop-scoped AI ceilings and attributable usage evidence. */
final class ShopAiGovernanceRepository {
 public function savePolicy(array $policy,string $environment='production'):array|WP_Error{
  global $wpdb;try{$projection=ShopAiPlan::evaluate($policy);}catch(\InvalidArgumentException $e){return new WP_Error('invalid_ai_policy',$e->getMessage());}
  $shop=(string)$projection['shop_key'];$json=wp_json_encode($policy);$hash=hash('sha256',(string)$json);$now=current_time('mysql',true);
  $existing=$wpdb->get_row($wpdb->prepare('SELECT id FROM '.Tables::shop_ai_policies().' WHERE shop_key=%s AND environment=%s',$shop,$environment),ARRAY_A);
  $data=['currency'=>(string)$projection['currency'],'policy'=>$json,'policy_hash'=>$hash,'state'=>'ACTIVE','updated_at'=>$now];
  if(is_array($existing)){$ok=$wpdb->update(Tables::shop_ai_policies(),$data,['id'=>(int)$existing['id']]);$id=(int)$existing['id'];}else{$data+=['shop_key'=>$shop,'environment'=>sanitize_key($environment),'created_by'=>get_current_user_id(),'created_at'=>$now];$ok=$wpdb->insert(Tables::shop_ai_policies(),$data);$id=(int)$wpdb->insert_id;}
  if($ok===false)return new WP_Error('ai_policy_persistence_failed','Shop AI policy could not be persisted.');
  return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::shop_ai_policies().' WHERE id=%d',$id),ARRAY_A)?:[];
 }
 public function recordUsage(array $input,?string $key=null):array|WP_Error{
  global $wpdb;$shop=sanitize_key((string)($input['shop_key']??''));$stage=sanitize_key((string)($input['stage']??''));if($shop===''||!in_array($stage,ShopAiPlan::STAGES,true))return new WP_Error('invalid_ai_usage','Valid shop_key and stage are required.');
  $data=['shop_key'=>$shop,'workflow'=>sanitize_key((string)($input['workflow']??'general')),'stage'=>$stage,'model_key'=>sanitize_text_field((string)($input['model_key']??'')),'product_id'=>absint($input['product_id']??0),'order_id'=>absint($input['order_id']??0),'quantity'=>max(1,(int)($input['quantity']??1)),'estimated_cost'=>max(0,(float)($input['estimated_cost']??0)),'actual_cost'=>max(0,(float)($input['actual_cost']??0)),'currency'=>strtoupper(substr(sanitize_text_field((string)($input['currency']??'USD')),0,3)),'occurred_at'=>current_time('mysql',true),'idempotency_key'=>$key===null?null:sanitize_text_field($key)];
  if($key!==null){$old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::shop_ai_usage().' WHERE idempotency_key=%s',$data['idempotency_key']),ARRAY_A);if(is_array($old))return $old+['idempotent_replay'=>true];}
  if($wpdb->insert(Tables::shop_ai_usage(),$data)!==1)return new WP_Error('ai_usage_persistence_failed','AI usage evidence could not be persisted.');return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::shop_ai_usage().' WHERE id=%d',(int)$wpdb->insert_id),ARRAY_A)?:[];
 }
 public function evaluate(string $shop,string $environment='production'):array|WP_Error{
  global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::shop_ai_policies().' WHERE shop_key=%s AND environment=%s AND state=%s',$shop,$environment,'ACTIVE'),ARRAY_A);if(!is_array($row))return new WP_Error('ai_policy_missing','Active shop AI policy is required.');
  $policy=json_decode((string)$row['policy'],true);if(!is_array($policy))return new WP_Error('ai_policy_corrupt','Shop AI policy is invalid.');
  $month=gmdate('Y-m-01 00:00:00');$usage=$wpdb->get_results($wpdb->prepare('SELECT stage,SUM(quantity) quantity,SUM(actual_cost) cost FROM '.Tables::shop_ai_usage().' WHERE shop_key=%s AND occurred_at>=%s GROUP BY stage',$shop,$month),ARRAY_A)?:[];$actual=[];foreach($usage as $u)$actual[(string)$u['stage']]=['count'=>(int)$u['quantity'],'cost'=>(float)$u['cost']];
  return ShopAiPlan::evaluate($policy,$actual);
 }
}