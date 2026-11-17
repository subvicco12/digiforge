<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
/** Pure minimized view of core-owned source claims. Hashes detect drift, never authenticate or grant authority. */
final class HeldPreparationReadModel {
 private static function jsonInput(array $r,int $depth=0):void {
  if($depth>30)throw new \InvalidArgumentException();
  foreach($r as $v){if(is_array($v))self::jsonInput($v,$depth+1);elseif(!is_null($v)&&!is_bool($v)&&!is_string($v)&&!is_int($v)&&!(is_float($v)&&is_finite($v)))throw new \InvalidArgumentException();}
  if($depth===0&&strlen(json_encode($r,JSON_THROW_ON_ERROR,32))>65536)throw new \InvalidArgumentException();
 }
 public static function fromRegistration(ShopBinding $s,int $actor,array $r):array|\WP_Error {
  try{
   self::jsonInput($r);
   $keys=['schema','version','scope_fingerprint','actor_id','evidence','journal','approval_verified','dispatch_permitted','native_product_version','native_version_counters_verified','companion_payload_equivalence_verified','native_contract_satisfied','independent_checkpoint_verified','external_execution_authorized','release_hold','evidence_hash'];
   if(array_diff(array_keys($r),$keys)||array_diff($keys,array_keys($r)))throw new \InvalidArgumentException();
   foreach(['schema'=>'DF_NATIVE_BUSINESS_MUTATION_REGISTRATION_1','version'=>'1.0.0','scope_fingerprint'=>$s->fingerprint(),'actor_id'=>$actor,'approval_verified'=>false,'dispatch_permitted'=>false,'native_product_version'=>null,'native_version_counters_verified'=>false,'companion_payload_equivalence_verified'=>false,'native_contract_satisfied'=>false,'independent_checkpoint_verified'=>false,'external_execution_authorized'=>false,'release_hold'=>true]as $k=>$v)if($r[$k]!==$v)throw new \InvalidArgumentException();
   $hash=$r['evidence_hash'];unset($r['evidence_hash']);if(!is_string($hash)||!hash_equals(MutationIntent::hash($r),$hash)||!is_array($r['evidence'])||!is_array($r['journal']))throw new \InvalidArgumentException();
   $view=self::observe($s,$actor,$r['evidence']);if(is_wp_error($view))return $view;$j=$r['journal'];if(array_diff(array_keys($j),['record','event_hash'])||!is_array($j['record']??null)||!is_string($j['event_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$j['event_hash']))throw new \InvalidArgumentException();
   $record=$j['record'];$rk=['schema','binding','candidate_key','state','revision','fence_hash','occurred_at','approval_verified','dispatch_permitted','independent_checkpoint_verified'];if(array_diff(array_keys($record),$rk)||array_diff($rk,array_keys($record))||!NativePortSchema::valid('binding',$record['binding']))throw new \InvalidArgumentException();
   foreach(['schema'=>'DF_NATIVE_HELD_JOURNAL_1','state'=>'HELD','revision'=>1,'fence_hash'=>null,'approval_verified'=>false,'dispatch_permitted'=>false,'independent_checkpoint_verified'=>false]as $k=>$v)if($record[$k]!==$v)throw new \InvalidArgumentException();
   foreach(['scope_fingerprint'=>$s->fingerprint(),'actor_id'=>$actor,'shop_id'=>$s->shopId,'shop_key'=>$s->shopKey,'integration_id'=>$s->integrationId,'credential_reference'=>$s->credential->integrationId,'provider_shop_id'=>$s->providerShopId,'environment'=>$s->environment,'authorization_revision'=>$s->revision,'payload_fingerprint'=>$r['evidence']['payload_fingerprint'],'intent_fingerprint'=>$r['evidence']['evidence_hash'],'approval_reference'=>$r['evidence']['plan_policy_hash'],'product_version'=>0,'expected_product_version'=>0]as $k=>$v)if($record['binding'][$k]!==$v)throw new \InvalidArgumentException();
   if(!is_string($record['candidate_key'])||!preg_match('/^[a-f0-9]{64}$/D',$record['candidate_key'])||!is_int($record['occurred_at'])||$record['occurred_at']<1||$record['occurred_at']>time()+30)throw new \InvalidArgumentException();
   return array_replace($view,['state'=>'HELD_SOURCE_ONLY','held_operation_id'=>$record['binding']['operation_id'],'held_event_hash'=>$j['event_hash']]);
  }catch(\Throwable){return new \WP_Error('printify_held_registration_denied','Exact nonexecuting held journal required; no native preparation or dispatch authorized.',['manual_review_required'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
 }
 public static function observe(ShopBinding $s,int $actor,array $r):array|\WP_Error {
  try{
   self::jsonInput($r);
   $keys=['schema','version','scope_fingerprint','actor_id','shop_key','integration_id','credential_reference','provider_shop_id','environment','product_id','product_version_id','provider_mapping_id','asset_spec_id','plan_hash','package_hash','artwork_version_hash','business_evidence_hash','artwork_evidence_hash','plan_evidence_hash','operation','payload_fingerprint','plan_policy_hash','plan_policy_revision','authorization_revision','native_product_version','native_version_counters_verified','business_package_binding_approved','companion_payload_equivalence_verified','mutation_authorized','independent_checkpoint_verified','external_execution_authorized','release_hold','evidence_hash'];
   if($s->shopKey!=='personalized_pod'||$s->shopId!==2||$actor<1||$s->expiresAt<=time()||array_diff(array_keys($r),$keys)||array_diff($keys,array_keys($r)))throw new \InvalidArgumentException();
   foreach(['schema'=>'DF_NATIVE_BUSINESS_MUTATION_EVIDENCE_1','version'=>'1.0.0','scope_fingerprint'=>$s->fingerprint(),'actor_id'=>$actor,'shop_key'=>$s->shopKey,'integration_id'=>$s->integrationId,'credential_reference'=>$s->credential->integrationId,'provider_shop_id'=>$s->providerShopId,'environment'=>$s->environment,'authorization_revision'=>$s->revision,'native_product_version'=>null,'native_version_counters_verified'=>false,'business_package_binding_approved'=>false,'companion_payload_equivalence_verified'=>false,'mutation_authorized'=>false,'independent_checkpoint_verified'=>false,'external_execution_authorized'=>false,'release_hold'=>true]as $k=>$v)if($r[$k]!==$v)throw new \InvalidArgumentException();
   foreach(['product_id','product_version_id','provider_mapping_id','asset_spec_id','plan_policy_revision']as $k)if(!is_int($r[$k])||$r[$k]<1)throw new \InvalidArgumentException();
   foreach(['plan_hash','package_hash','artwork_version_hash','business_evidence_hash','artwork_evidence_hash','plan_evidence_hash','payload_fingerprint','plan_policy_hash','evidence_hash']as $k)if(!is_string($r[$k])||!preg_match('/^[a-f0-9]{64}$/D',$r[$k]))throw new \InvalidArgumentException();
   if($r['operation']!=='IMAGE_UPLOAD')throw new \InvalidArgumentException();$hash=$r['evidence_hash'];unset($r['evidence_hash']);if(!hash_equals(MutationIntent::hash($r),$hash))throw new \InvalidArgumentException();
   return ['schema'=>'DF_COMPANION_HELD_SOURCE_VIEW_1','scope_fingerprint'=>$s->fingerprint(),'actor_id'=>$actor,'operation'=>$r['operation'],'business_product_id'=>$r['product_id'],'business_product_version_id'=>$r['product_version_id'],'provider_mapping_id'=>$r['provider_mapping_id'],'asset_spec_id'=>$r['asset_spec_id'],'source_evidence_hash'=>$hash,'state'=>'SOURCE_OBSERVED_MUTATION_BLOCKED','native_product_version'=>null,'mutation_authorized'=>false,'companion_payload_equivalence_verified'=>false,'independent_checkpoint_verified'=>false,'trusted_receipt'=>false,'source_authenticated'=>false,'current_core_verification_required'=>true,'manual_review_required'=>true,'retry_permitted'=>false,'network_request_permitted'=>false,'external_execution_authorized'=>false];
  }catch(\Throwable){return new \WP_Error('printify_held_source_denied','Current exact scoped core source evidence required; no mutation authorized.',['manual_review_required'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
 }
}
