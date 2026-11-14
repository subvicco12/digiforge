<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** Fixed, non-dispatching adapter to inspected native contracts. Source pin is not runtime attestation. */
final class CoreContractAdapter {
 public const REFERENCE_SHA='509abc0ea3b6e6a6ad855dcea24228e45bce4e5e';
 public static function inspect():array {
  $surface=['DigiForge\\Security\\ShopAccess'=>['authorize',3],'DigiForge\\PortalAccess\\Owner'=>['forActor',2],'DigiForge\\POD\\ScopedProviderConnection'=>['verified',3],'DigiForge\\POD\\ControlledExecutionTransaction'=>['execute',7],'DigiForge\\Listings\\PublishReviewRepository'=>['inspect',1],'DigiForge\\Orders\\FulfillmentEvidence'=>['validate',3]];$missing=[];
  foreach($surface as $class=>[$method,$count])try{if(!class_exists($class)||!method_exists($class,$method)||(new \ReflectionMethod($class,$method))->getNumberOfParameters()!==$count)$missing[]=$class.'::'.$method;}catch(\Throwable){$missing[]=$class.'::'.$method;}
  $read='DigiForge\\Connector\\Printify\\Bridge';if(!class_exists($read)||!method_exists($read,'connect'))$missing[]=$read.'::connect';
  $port='DigiForge\\Connector\\Printify\\MutationBridge';$available=class_exists($port)&&method_exists($port,'connect');if(!$available)$missing[]=$port.'::connect';
  return ['adapter_version'=>'1.0.0','reference_sha'=>self::REFERENCE_SHA,'missing'=>$missing,'native_ports_available'=>false,'bridge_registered'=>$available,'runtime_equivalence_verified'=>false,'execution_authorized'=>false,'etsy_review_is_printify_authority'=>false];
 }
 public static function validateFulfillment(array $shipping,array $cost,string $currency):array|\WP_Error {
  try{$class='DigiForge\\Orders\\FulfillmentEvidence';if(!class_exists($class)||!method_exists($class,'validate'))return self::error();$r=$class::validate($shipping,$cost,$currency);if(is_wp_error($r)||($r['state']??null)!=='FULFILLMENT_EVIDENCE_VALIDATED'||($r['external_execution_performed']??null)!==false)return self::error();return ['state'=>'LOCAL_FULFILLMENT_EVIDENCE_VALIDATED','currency'=>$r['currency'],'execution_authorized'=>false,'core_review_required'=>true];}catch(\Throwable){return self::error();}
 }
 public static function localScope(string $shop,int $actor):array|\WP_Error {
  try{if(!function_exists('get_current_user_id')||get_current_user_id()!==$actor||$actor<1||!class_exists(\DigiForge\Security\ShopAccess::class))return self::error();foreach(['manage_digiforge_connections','manage_digiforge_orders']as $cap)if(\DigiForge\Security\ShopAccess::authorize($shop,$cap,$actor)!==true)return self::error();return ['shop_key'=>$shop,'actor_id'=>$actor,'native_local_scope_checked'=>true,'review_mutation_supported'=>false,'execution_authorized'=>false];}catch(\Throwable){return self::error();}
 }
 private static function error():\WP_Error{return new \WP_Error('printify_native_core_contract_denied','Compatible native local evidence contract and current core authority required.',['execution_authorized'=>false]);}
}
