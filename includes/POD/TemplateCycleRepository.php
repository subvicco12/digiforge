<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\AI\ShopAiGovernanceRepository;
use DigiForge\Database\Tables;
use WP_Error;
/** Conservative durable reservations for new templates, never external authority. */
final class TemplateCycleRepository {
 public function snapshot(string $shop,array $policy):array|WP_Error {
  global $wpdb;
  try{$cycle=TemplateCyclePolicy::at((array)($policy['template_cap']??[]),time());}catch(\Throwable $e){return new WP_Error('template_cycle_policy_required','An explicit valid template-cycle cap is required.');}
  $wpdb->last_error='';$used=$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(quantity),0) FROM '.Tables::shop_ai_usage().' WHERE shop_key=%s AND workflow=%s AND stage=%s AND run_id=%s',$shop,'production_template','template_prepare',$cycle['cycle_id']));
  if(!empty($wpdb->last_error)||!is_numeric($used)||(int)$used<0)return new WP_Error('template_cycle_evidence_unavailable','Template cycle usage evidence is unavailable.');
  return $cycle+['shop_key'=>$shop,'used'=>(int)$used,'remaining'=>max(0,$cycle['limit']-(int)$used),'state'=>'AVAILABLE','planning_only'=>true];
 }
 public function assertOwner(string $shop,string $fingerprint):true|WP_Error {
  global $wpdb;$shop=sanitize_key($shop);if($shop==='')return new WP_Error('template_cycle_scope_required','Explicit shop ownership is required.');
  $wpdb->last_error='';$owners=$wpdb->get_col($wpdb->prepare('SELECT DISTINCT shop_key FROM '.Tables::shop_ai_usage().' WHERE workflow=%s AND stage=%s AND model_key=%s','production_template','template_prepare',$fingerprint));
  if(!empty($wpdb->last_error)||!is_array($owners))return new WP_Error('template_cycle_evidence_unavailable','Template ownership evidence is unavailable.');
  if($owners!==[$shop])return new WP_Error('template_cycle_owner_mismatch','Template reservation ownership is absent or belongs to another shop; explicit reconciliation is required.');
  return true;
 }
 public function reserve(string $shop,array $template):array|WP_Error {
  global $wpdb;$shop=sanitize_key($shop);if($shop==='')return new WP_Error('template_cycle_scope_required','A shop is required for a new production-template candidate.');
  $lock=ShopAiGovernanceRepository::generationLockName($shop);$wpdb->last_error='';$acquired=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));
  if(!empty($wpdb->last_error)||(string)$acquired!=='1')return new WP_Error('template_cycle_reservation_busy','Shop template reservation is unavailable or busy.');
  try{$result=$this->reserveLocked($shop,$template);}catch(\Throwable $e){$result=new WP_Error('template_cycle_reservation_uncertain','Template reservation outcome is uncertain; creation is blocked.');}
  finally{$wpdb->last_error='';$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$uncertain=!empty($wpdb->last_error)||(string)$released!=='1';}
  if($uncertain)return new WP_Error('template_cycle_release_uncertain','Template reservation release is uncertain; creation is blocked.');
  return $result;
 }
 private function reserveLocked(string $shop,array $template):array|WP_Error {
  global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT policy FROM '.Tables::shop_ai_policies().' WHERE shop_key=%s AND environment=%s AND state=%s',$shop,'production','ACTIVE'),ARRAY_A);
  if(!empty($wpdb->last_error))return new WP_Error('template_cycle_evidence_unavailable','Template policy evidence is unavailable.');
  if(!is_array($row))return new WP_Error('template_cycle_policy_required','An active shop template-cycle policy is required.');
  $policy=json_decode((string)$row['policy'],true);if(!is_array($policy))return new WP_Error('template_cycle_policy_required','Template policy is invalid.');
  $cycle=$this->snapshot($shop,$policy);if($cycle instanceof WP_Error)return $cycle;
  $key='template-'.hash('sha256',wp_json_encode([$shop,$cycle['cycle_id'],$template['template_id'],$template['template_version'],$template['fingerprint']]));
  $wpdb->last_error='';$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::shop_ai_usage().' WHERE idempotency_key=%s',$key),ARRAY_A);
  if(!empty($wpdb->last_error))return new WP_Error('template_cycle_evidence_unavailable','Template reservation history is unavailable.');
  if(is_array($existing))return $cycle+['reservation_id'=>(int)$existing['id'],'idempotent_replay'=>true];
  if($cycle['remaining']<1)return new WP_Error('template_cycle_limit_reached','The shop template-cycle cap has been reached.',['cycle'=>$cycle]);
  $data=['shop_key'=>$shop,'workflow'=>'production_template','run_id'=>$cycle['cycle_id'],'run_started_at'=>$cycle['starts_at'],'stage'=>'template_prepare','model_key'=>$template['fingerprint'],'product_id'=>0,'order_id'=>0,'quantity'=>1,'estimated_cost'=>0,'actual_cost'=>0,'currency'=>strtoupper((string)($policy['currency']??'USD')),'occurred_at'=>current_time('mysql',true),'idempotency_key'=>$key];
  $wpdb->last_error='';if($wpdb->insert(Tables::shop_ai_usage(),$data)!==1||!empty($wpdb->last_error))return new WP_Error('template_cycle_reservation_uncertain','Template slot could not be confirmed; creation is blocked.');
  $id=(int)$wpdb->insert_id;$wpdb->last_error='';$confirmed=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::shop_ai_usage().' WHERE id=%d',$id),ARRAY_A);
  if(!empty($wpdb->last_error)||!is_array($confirmed)||!hash_equals($key,(string)$confirmed['idempotency_key']))return new WP_Error('template_cycle_reservation_uncertain','Template reservation cannot be independently read back.');
  return $cycle+['reservation_id'=>$id,'idempotent_replay'=>false];
 }
}
