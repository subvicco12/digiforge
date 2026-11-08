<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Listings\Validator;
use DigiForge\Security\ShopAccess;
use WP_Error;
/** Join reviewed buyer proof to existing provider contracts. No transport, credentials or reservations. */
final class PersonalizedPrintifyPreparation {
 public function plan(string $proof,int $integration,string $line,?string $package=null):array|WP_Error {
  if(!Shop2ProductWorkflow::access(true)||ShopAccess::authorize('personalized_pod','manage_digiforge_connections')!==true)return PersonalizedProofRepository::error('provider_access');
  $held=(new PersonalizedProofRepository())->export($proof,$line,$package);if(is_wp_error($held))return $held;
  $i=(new \DigiForge\Integrations\Repository())->find($integration);if(is_wp_error($i))return $i;
  if(!is_array($i)||($i['provider']??null)!=='printify'||($i['config']['shop_key']??null)!=='personalized_pod'||!is_string($i['config']['shop_id']??null)||!in_array($i['environment']??null,['test','live'],true))return PersonalizedProofRepository::error('provider_scope');
  $p=$held['prepared'];$scope=PrintifyConnectionBoundary::context('personalized_pod',$integration,'PROVIDER_ORDER_SUBMIT');$gates=$held['remaining_gates'];if(is_wp_error($scope))$gates[]='EXACT_REVIEWED_PROVIDER_CONNECTION_REQUIRED';
  try{$request=PrintifyProductRequest::prepare('PRODUCT_READ',$i['config']['shop_id'],['product_id'=>$p['product_id']]);}catch(\Throwable){return PersonalizedProofRepository::error('provider_contract');}
  $intent=['proof_reference'=>$proof,'review_sha256'=>$held['review_sha256'],'order_line_reference'=>$line,'integration_id'=>$integration,'environment'=>$i['environment'],'external_shop_id'=>$i['config']['shop_id'],'product_id'=>$p['product_id'],'selection'=>array_intersect_key($p['supplier'],array_flip(['blueprint_id','provider_id','variant_id'])),'template_sha256'=>$p['template_sha256'],'personalization_sha256'=>$p['personalization_sha256'],'fixed_artwork_sha256'=>$p['artwork_sha256']];
  return ['schema'=>'held-printify-personalized-preparation-v1','shop_key'=>'personalized_pod','intent'=>$intent,'correlation_reference'=>hash('sha256',Validator::canonicalJson($intent)),'readback_request'=>$request,'native_layer_instructions'=>$p['native_bindings'],'fixed_artifact'=>$p['production']['fixed_artifact'],'shipping_evidence'=>$p['supplier']['evidence']['shipping'],'size_guide_evidence'=>$p['supplier']['evidence']['size_guides'],'product_lifecycle'=>'EXISTING_UNVERIFIED_PRODUCT_REQUIRES_READBACK','connection_state'=>is_wp_error($scope)?'UNVERIFIED':'VERIFIED_LOCAL_BINDING','held_package'=>$held,'remaining_gates'=>array_values(array_unique(array_merge($gates,['CURRENT_PROVIDER_PRODUCT_VARIANT_AND_GEOMETRY_READBACK_REQUIRED','ORDER_ADDRESS_PAYMENT_AND_LINE_MAPPING_REQUIRED','SEPARATE_PRODUCTION_AUTHORIZATION_REQUIRED']))),'production_cost'=>null,'tax_amount'=>null,'destination_shipping_rate'=>null,'state'=>'BLOCKED','external_execution_authorized'=>false,'reservation_created'=>false,'product_save_permitted'=>false,'product_create_permitted'=>false,'order_submission_permitted'=>false];
 }
}
