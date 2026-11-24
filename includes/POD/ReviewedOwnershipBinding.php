<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Database\Tables;
use DigiForge\Integrations\Repository;
use DigiForge\Portal\ProductEvidenceScope;
use DigiForge\Security\ReviewedLocalEvidence;
use DigiForge\Security\ShopAccessPolicy;
use WP_Error;

/** Reviewed local assertions only. Neither configured identities nor synthetic evidence certify a provider account. */
final class ReviewedOwnershipBinding
{
 private const INPUT=['integration_id','provider_mapping_id','production_template_id','source_sha256','evidence_reference'];
 public function propose(string $shop,array $input,int $effectiveAt,int $expiresAt,string $requestId,int $expectedRevision):array|WP_Error
 {
  if(array_diff(array_keys($input),self::INPUT)!==[]||count($input)!==count(self::INPUT))return self::error('invalid_input',400);
  foreach(['integration_id','provider_mapping_id','production_template_id']as $field)if(!is_int($input[$field])||$input[$field]<1)return self::error('invalid_input',400);
  $access=\DigiForge\Security\ShopAccess::authorize($shop,'manage_digiforge_connections');if(is_wp_error($access))return $access;
  $payload=$this->snapshot($shop,$input);if(is_wp_error($payload))return $payload;
  $key=$this->key($shop,$payload);$result=ReviewedLocalEvidence::propose('ownership',$shop,$key,$payload,$effectiveAt,$expiresAt,$requestId,$expectedRevision);if(is_array($result))$result['binding_key']=$key;return $result;
 }
 public function decide(string $shop,string $key,int $revision,string $hash,string $decision,string $requestId):array|WP_Error
 {
  // Revocation/rejection remain possible after a dependency becomes stale.
  if($decision==='APPROVED'){$state=$this->current($shop,$key);if(is_wp_error($state))return $state;if(!is_array($state))return self::error('missing',409);$valid=$this->validateState($shop,$key,$state);if(is_wp_error($valid))return $valid;}
  return ReviewedLocalEvidence::decide('ownership',$shop,$key,$revision,$hash,$decision,$requestId);
 }
 public function current(string $shop,string $key):array|WP_Error|null { $state=ReviewedLocalEvidence::read('ownership',$shop,$key);if(is_wp_error($state)||$state===null)return $state;$valid=$this->validateState($shop,$key,$state);return is_wp_error($valid)?$valid:$state; }
 public function verified(string $shop,string $key):array|WP_Error
 {
  $state=ReviewedLocalEvidence::approved('ownership',$shop,$key);if(is_wp_error($state))return $state;
  $valid=$this->validateState($shop,$key,$state);return is_wp_error($valid)?$valid:$state;
 }
 private function validateState(string $shop,string $key,array $state):true|WP_Error
 {
  $payload=$state['payload']??null;if(!is_array($payload))return self::error('invalid_state',409);
  $input=array_intersect_key($payload,array_flip(self::INPUT));$current=$this->snapshot($shop,$input);if(is_wp_error($current))return $current;
  if(!hash_equals($key,$this->key($shop,$current))||self::json($payload)!==self::json($current))return self::error('stale_or_conflicting',409);return true;
 }
 private function snapshot(string $shop,array $input):array|WP_Error
 {
  if(array_diff(array_keys($input),self::INPUT)!==[]||count($input)!==count(self::INPUT))return self::error('invalid_input',400);
  foreach(['integration_id','provider_mapping_id','production_template_id']as $field)if(!is_int($input[$field])||$input[$field]<1)return self::error('invalid_input',400);
  if(!is_string($input['source_sha256'])||preg_match('/^[a-f0-9]{64}$/D',$input['source_sha256'])!==1||!is_string($input['evidence_reference'])||preg_match('#^local://[A-Za-z0-9/_:.-]{1,240}$#D',$input['evidence_reference'])!==1)return self::error('invalid_input',400);
  if(!ShopAccessPolicy::validShop($shop))return self::error('shop',400);
  try{
   $integration=(new Repository())->find($input['integration_id']);if(is_wp_error($integration))return $integration;
   if(!is_array($integration)||$integration['provider']!=='printify'||!in_array($integration['environment'],['sandbox','test','production'],true)||$integration['status']!=='CONFIGURED')return self::error('integration',409);
   $config=$integration['config'];$account=$config['account_reference']??null;$providerShop=$config['shop_id']??null;
   // New explicit local config contract: opaque provider account reference; never a WP actor ID.
   if(!is_string($account)||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}$/D',$account)!==1||!is_string($providerShop)||preg_match('/^[1-9][0-9]{0,18}$/D',$providerShop)!==1||($config['shop_key']??null)!==$shop)return self::error('configured_identity_required',409);
   global $wpdb;$wpdb->last_error='';$identities=$wpdb->get_results($wpdb->prepare('SELECT id,config FROM '.Tables::integrations().' WHERE provider=%s AND environment=%s AND status=%s LIMIT 1001','printify',$integration['environment'],'CONFIGURED'),ARRAY_A);
   if($wpdb->last_error!==''||!is_array($identities)||count($identities)>1000)return self::error('dependencies_unavailable',503);
   foreach($identities as $identity){if((int)$identity['id']===$input['integration_id'])continue;$other=json_decode((string)$identity['config'],true);if(!is_array($other))return self::error('conflicting_identity',409);if(($other['shop_id']??null)===$providerShop)return self::error('conflicting_identity',409);}
   $mapping=$this->row(Tables::pod_mappings(),$input['provider_mapping_id']);if(is_wp_error($mapping))return $mapping;
   if($mapping['provider']!==$integration['provider']||$mapping['environment']!==$integration['environment'])return self::error('mapping_identity',409);
   $owner=(new BusinessScopeRepository())->assertActiveOwnershipForMapping($input['provider_mapping_id']);if(is_wp_error($owner))return $owner;
   global $wpdb;$wpdb->last_error='';$owners=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.Tables::pod_business_mappings().' WHERE provider_mapping_id=%d',$input['provider_mapping_id']));if($wpdb->last_error!==''||!is_array($owners))return self::error('dependencies_unavailable',503);if(count($owners)!==1||(int)$owners[0]!==(int)$owner['id'])return self::error('ownership_conflict',409);
   if((int)$owner['product_version_id']!==(int)$mapping['product_version_id'])return self::error('ownership_ancestry',409);
   $configured=$config['catalog_read_scope']??null;if(!is_array($configured))return self::error('configured_scope_required',409);
   $scope=BusinessScope::resolveConfigured($configured);
   foreach(['business_id','store_id','product_program_id']as $field)if($scope[$field] !== (int)$owner[$field])return self::error('ownership_ancestry',409);
   $version=$this->row(Tables::product_versions(),(int)$mapping['product_version_id'],ProductEvidenceScope::forTable(Tables::product_versions(),$shop));if(is_wp_error($version))return $version;
   $plan=$this->row(Tables::production_plans(),(int)$mapping['production_plan_id']);if(is_wp_error($plan))return $plan;if((int)$plan['product_version_id']!==(int)$version['id'])return self::error('plan_ancestry',409);
   $template=$this->row(Tables::pod_production_templates(),$input['production_template_id']);if(is_wp_error($template))return $template;
   foreach(['variant_ids','print_areas']as $field)$template[$field]=json_decode($template[$field],true,40,JSON_THROW_ON_ERROR);
   $normalized=ProductionTemplateContract::normalize($template);
   if(!hash_equals((string)$template['fingerprint'],$normalized['fingerprint'])||$normalized['supplier']!==$mapping['provider']||$normalized['template_status']==='RETIRED'||(string)$normalized['provider_blueprint_id']!==$mapping['provider_product_key']||!preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/D',$mapping['provider_variant_key'],$match)||(int)$match[1]!==$normalized['provider_id']||!in_array((int)$match[2],$normalized['variant_ids'],true))return self::error('template_identity',409);
   // Rebuild from ALL currently stored variants to verify environment and exact source/geometry bindings.
   $catalog=[];foreach($normalized['variant_ids']as $variant){$row=$this->catalog($mapping['environment'],(string)$normalized['provider_blueprint_id'],$normalized['provider_id'].':'.$variant);if(is_wp_error($row))return $row;$catalog[]=$row;}
   $fields=array_intersect_key($normalized,array_flip(['template_id','template_version','personalization_pipeline','personalization_engine','template_status']));
   $rebuilt=ProductionTemplateContract::fromPrintifyVariants($catalog,$normalized['print_areas'],$fields);
   if($rebuilt['fingerprint']!==$normalized['fingerprint'])return self::error('catalog_changed',409);
   return $input+['schema'=>'reviewed-local-ownership-v1','evidence_state'=>'SYNTHETIC','shop_key'=>$shop,'environment'=>$mapping['environment'],'provider'=>$mapping['provider'],'account_reference'=>$account,'shop_id'=>$providerShop,'business_id'=>$scope['business_id'],'store_id'=>$scope['store_id'],'product_program_id'=>$scope['product_program_id'],'product_program'=>$scope['product_program'],'product_version_id'=>(int)$version['id'],'production_plan_id'=>(int)$plan['id'],'provider_product_key'=>$mapping['provider_product_key'],'provider_variant_key'=>$mapping['provider_variant_key'],'template_id'=>$normalized['template_id'],'template_version'=>$normalized['template_version'],'template_fingerprint'=>$normalized['fingerprint'],'integration_version'=>hash('sha256',self::json($integration)),'dependency_sha256'=>hash('sha256',self::json([$mapping,$owner,$version,$plan,$template,$catalog])),'provider_identity_verified'=>false,'supplier_certified'=>false,'external_execution_authorized'=>false];
  }catch(\Throwable){return self::error('dependencies_unavailable',503);}
 }
 private function key(string $shop,array $payload):string {return hash('sha256',self::json([$shop,$payload['environment'],$payload['provider_mapping_id'],$payload['production_template_id']]));}
 private function row(string $table,int $id,?string $predicate=null):array|WP_Error
 {
  global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d'.($predicate===null?'':' AND '.$predicate),$id),ARRAY_A);
  if($wpdb->last_error!=='')return self::error('dependencies_unavailable',503);return is_array($row)?$row:self::error('missing_dependency',409);
 }
 private function catalog(string $environment,string $product,string $variant):array|WP_Error
 {
  global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::pod_catalog().' WHERE provider=%s AND environment=%s AND provider_product_key=%s AND provider_variant_key=%s','printify',$environment,$product,$variant),ARRAY_A);
  if($wpdb->last_error!=='')return self::error('dependencies_unavailable',503);return is_array($rows)&&count($rows)===1?$rows[0]:self::error('catalog_identity',409);
 }
 private static function json(array $input):string {$sort=static function(array $a)use(&$sort):array{foreach($a as &$v)if(is_array($v))$v=$sort($v);unset($v);if(!array_is_list($a))ksort($a,SORT_STRING);return $a;};return json_encode($sort($input),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
 private static function error(string $code,int $status):WP_Error {return new WP_Error('digiforge_ownership_'.$code,'Current exact stored ownership dependencies and independent local review are required.',['status'=>$status,'external_execution_authorized'=>false,'supplier_certified'=>false]);}
}
