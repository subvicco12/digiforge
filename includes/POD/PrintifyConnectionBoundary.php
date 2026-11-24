<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Security\ReviewedLocalEvidence;
use DigiForge\Listings\Validator;
use WP_Error;

/** DF-OWN-002: an additional ownership prerequisite, never an execution or credential grant. */
final class PrintifyConnectionBoundary
{
 private const FIELDS=['installation_sha256','environment','business_id','store_id','program_id','shop_key','provider','external_account_reference','external_shop_reference','integration_id','capability'];
 /** Capture the exact approved local tuple for a subsequently consent-bound request. No activation follows. */
 public static function context(string $shop,int $integration,string $capability):array|WP_Error {
  $valid=(new ScopedProviderConnection())->verified($shop,$integration,$capability);if(is_wp_error($valid))return $valid;
  $key=hash('sha256',Validator::canonicalJson(['DF-OWN-001',$shop,$integration,$capability]));
  $state=ReviewedLocalEvidence::approved('ownership',$shop,$key);if(is_wp_error($state))return $state;
  if(($state['proposal_hash']??null)!==$valid['binding_hash'])return self::error();
  $context=array_intersect_key($state['payload'],array_flip(self::FIELDS));
  if(count($context)!==count(self::FIELDS))return self::error();
  return $context+['binding_hash'=>$valid['binding_hash']];
 }
 public static function authorize(int $integration,string $capability,array $context,?string $externalShop=null):true|WP_Error {
  if(count($context)!==count(self::FIELDS)+1||array_diff(array_keys($context),array_merge(self::FIELDS,['binding_hash']))||($context['integration_id']??null)!==$integration||($context['capability']??null)!==$capability||!is_string($context['shop_key']??null))return self::error();
  $current=self::context($context['shop_key'],$integration,$capability);if(is_wp_error($current))return $current;
  try{if(Validator::canonicalJson($current)!==Validator::canonicalJson($context)||($externalShop!==null&&!hash_equals($current['external_shop_reference'],$externalShop)))return self::error();}catch(\Throwable){return self::error();}
  return true;
 }
 /** Check existing vault metadata; never return, persist or log material. */
 public static function material(int $integration,string $value,?string $name=null):true|WP_Error {
  if($value===''||strlen($value)>8192||preg_match('/[\x00-\x20\x7f]/',$value))return self::error();
  $record=(new \DigiForge\Integrations\Repository())->find($integration);if(is_wp_error($record))return $record;if(!is_array($record))return self::error();
  foreach($record['secrets']as $item){$candidate=$item['secret_name'];if(!in_array($candidate,['personal_access_token','access_token'],true)||($name!==null&&$candidate!==$name))continue;
   try{$fingerprint=\DigiForge\Integrations\CredentialVault::fingerprint($value,\DigiForge\Integrations\Repository::secretContext($integration,$candidate));}catch(\Throwable){return self::error();}
   if(is_string($item['fingerprint']??null)&&hash_equals($item['fingerprint'],$fingerprint))return true;
  }
  return new WP_Error('printify_connection_material_changed','Scoped connection material does not match reviewed vault metadata.',['status'=>409]);
 }
 public static function runtime(int $integration,string $capability,array $context,?string $externalShop=null):true|WP_Error {
  if(!\DigiForge\Core\Settings::is_enabled('printify')||(in_array($capability,['PROVIDER_ORDER_SUBMIT','PROVIDER_PRODUCTION_AUTHORIZE'],true)&&!\DigiForge\Core\Settings::is_enabled('order_automation')))return new WP_Error('printify_connection_runtime_locked','Existing Printify runtime interlocks remain locked.',['status'=>409]);
  $binding=self::authorize($integration,$capability,$context,$externalShop);if(is_wp_error($binding))return $binding;
  $record=(new \DigiForge\Integrations\Repository())->find($integration);if(is_wp_error($record))return $record;
  return is_array($record)&&($record['status']??null)==='CONFIGURED'&&($record['enabled']??null)===true?true:self::error();
 }
 public static function error():WP_Error {return new WP_Error('printify_connection_scope_required','Current exact independently reviewed connection scope is required.',['status'=>409,'external_execution_authorized'=>false]);}
}
