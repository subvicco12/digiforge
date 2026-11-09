<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Core\Settings;
/** Owner-only local metadata observation. No provider, vault consumption or activation. Existing denial security audits remain. */
final class PrintifyAcceptanceReadiness {
 public static function snapshot():array|\WP_Error {
  $permission=PrintifyAcceptanceAbilities::permission();if(is_wp_error($permission))return $permission;
  $i=(new \DigiForge\Integrations\Repository())->find(3);$available=is_array($i);$config=$available&&is_array($i['config']??null)?$i['config']:[];
  $metadata=false;if($available)foreach($i['secrets']??[] as $item)if(in_array($item['secret_name']??null,['personal_access_token','access_token'],true))$metadata=true;
  $e=['credential_reference_present'=>$metadata,'integration_enabled'=>$available&&($i['enabled']??null)===true];
  $proposal=PrintifyAcceptanceBinding::proposal();$binding=PrintifyAcceptanceBinding::validate($proposal,$e,time());
  $scopes=[];foreach(['printify.connection.read','printify.catalog.read','printify.lifecycle.prepare','printify.product.prepare'] as $cap)$scopes[$cap]=(new ScopedProviderConnection())->status('personalized_pod',3,$cap)['status'];
  $abilities=[];foreach(['execute','receipt','revoke','readiness'] as $op)$abilities[$op]=function_exists('wp_get_ability')&&wp_get_ability('digiforge/printify-acceptance-'.$op)!==null;
  return ['schema'=>'printify-acceptance-readiness-v1','status'=>'BLOCKED_AUTHORITY','read_only'=>true,'provider_requests'=>0,'runtime_execution_authorized'=>false,'policy'=>PrintifyAcceptancePolicy::decision('GET',Settings::get('stop_all',true)!==false,Settings::safety_locked()),'safety'=>['stop_all'=>Settings::get('stop_all',true),'external_lock'=>Settings::safety_locked(),'automation_armed'=>Settings::get('automation_armed',false),'activation_authorized'=>Settings::get('activation_authorized',false)],'integration'=>['id'=>3,'record_available'=>$available,'provider_is_printify'=>$available&&($i['provider']??null)==='printify','environment_is_production'=>$available&&($i['environment']??null)==='production','enabled'=>$e['integration_enabled'],'credential_metadata_present'=>$metadata,'shop2_key_matches'=>($config['shop_key']??null)==='personalized_pod','provider_shop_matches'=>($config['shop_id']??null)==='28930593','catalog_scope_present'=>is_array($config['catalog_read_scope']??null)],'proposal_validation'=>$binding,'connection_capabilities'=>$scopes,'interface'=>['status'=>'INTERFACE_SOURCE_VERIFIED','core_api_present'=>function_exists('wp_register_ability'),'registered'=>$abilities,'connector_status'=>'BLOCKED_TOOL','connector_owner_session_authentication'=>'UNVERIFIED','production_availability'=>'UNVERIFIED'],'evidence_limit'=>'Local metadata only; no live account, credential scope, provider or connector verification'];
 }
}
