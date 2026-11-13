<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
use DigiForgePrintifyConnector\Preparation\{NativeCorePortInterface,ShipmentReadModel};
use DigiForge\Core\Settings;
use DigiForge\PortalAccess\Owner;

/** Native typed boundary. Missing native evidence is a denial, never a synthetic acknowledgement. */
final class NativeCorePort implements NativeCorePortInterface
{
 public function version():string{return '1.0.0';}
 public function inspectionVersion():string{return '1.0.0';}
 public function publicationVersion():string{return '1.0.0';}
 public function workflowVersion():string{return '1.0.0';}
 public function operationsVersion():string{return '1.0.0';}
 public function contractManifest():array {
  return ['schema'=>'DF_NATIVE_CORE_MANIFEST_1','contract_version'=>'1.0.0','spec_sha256'=>NativePortContract::digest(),'core_source_sha'=>NativeBuildIdentity::source(),'authority'=>'DIGIFORGE_CORE','protocols'=>array_fill_keys(['base','inspection','publication','workflow','operations','native'],'1.0.0'),
   // Complete ordinary GET admission and protected panel access only; mutation/recovery stay denied.
   'capabilities'=>['PANEL','GET_READ'],'external_execution_authorized'=>false];
 }
 public function read(ExternalOperationIntent $intent):ProviderResult|\WP_Error{return (new NativeReadConnector())->read($intent);}
 public function exchange(array $request):array|\WP_Error {
  if(!NativePortContract::valid('mutation_request',$request))return NativeFailure::error('mutation_contract');
  $b=$request['binding'];$c=$request['context'];
  foreach(['actor_id','authorization_revision','approval_reference','product_version','expected_product_version']as $key)if($b[$key]!==$c[$key])return NativeFailure::error('mutation_binding');
  if($b['credential_reference']!==$b['integration_id']||(['personalized_pod'=>2,'standard_pod'=>3][$b['shop_key']]??null)!==$b['shop_id'])return NativeFailure::error('mutation_scope');
  $access=NativeAuthorityAdapter::module($b['shop_key'],$b['actor_id'],['manage_digiforge_pod']);if(is_wp_error($access))return $access;
  // Core's existing option-backed claims are not the specified transactional native journal.
  // Never call ControlledExecutionTransaction::execute or trust caller receipts as a passive helper.
  return NativeFailure::error('journal_and_independent_checkpoint_required');
 }
 public function inspect(array $binding):array|\WP_Error {
  if(!NativePortContract::valid('binding',$binding))return NativeFailure::error('inspection_contract');
  $access=NativeAuthorityAdapter::module($binding['shop_key'],$binding['actor_id'],['manage_digiforge_pod']);
  return is_wp_error($access)?$access:NativeFailure::error('trusted_operation_identity_join_required');
 }
 public function authorizePublication(array $binding,array $prepared):true|\WP_Error {
  if(!NativePortContract::valid('binding',$binding)||!NativePortContract::valid('json_object',$prepared))return NativeFailure::error('publication_contract');
  $access=NativeAuthorityAdapter::module($binding['shop_key'],$binding['actor_id'],['manage_digiforge_listings']);if(is_wp_error($access))return $access;
  try{if(Settings::safety_locked()||Settings::get('stop_all',true)!==false)return NativeFailure::error('external_locked');if(Owner::require($binding['shop_key'])!==true)return NativeFailure::error('fresh_global_owner_required');}catch(\Throwable){return NativeFailure::error('publication_authority_unavailable');}
  return NativeFailure::error('publication_approval_and_checkpoint_join_required');
 }
 public function authorizeWorkflow(ShopBinding $scope,int $actor,string $action):array|\WP_Error {
  $caps=match($action){'inspect_workflows','inspect_fulfillment','inspect_allocations'=>['manage_digiforge_orders'],'inspect_reviews'=>['manage_digiforge_listings','manage_digiforge_orders'],'inspect_recovery','panel_access'=>['manage_digiforge_pod'],default=>null};
  if($caps===null)return NativeFailure::error('workflow_write_identity_join_required');
  $current=NativeAuthorityAdapter::scope($scope,$actor,$caps);if(is_wp_error($current))return $current;
  return ['scope_fingerprint'=>$scope->fingerprint(),'actor_id'=>$actor,'action'=>$action,'authorization_revision'=>$scope->revision,'local_evidence_authorized'=>true,'external_writes_denied'=>true,'expires_at'=>$current['expires_at']];
 }
 public function withWebhookSecret(ShopBinding $scope,callable $verify):mixed {
  // Existing webhook verification owns its secret internally. Never pass it to a companion callback.
  return NativeFailure::error('exclusive_webhook_custody_bridge_required');
 }
 public function recordWorkflow(array $envelope):array|\WP_Error{return NativeFailure::error('atomic_workflow_identity_join_required');}
 public function inspectWorkflows(ShopBinding $scope,int $actor,int $limit):array|\WP_Error{return $this->view($scope,$actor,$limit,'inspect_workflows','trusted_workflow_snapshot_join_required');}
 public function reviewQueue(ShopBinding $scope,int $actor,int $limit):array|\WP_Error{return $this->view($scope,$actor,$limit,'inspect_reviews','trusted_review_snapshot_join_required');}
 private function view(ShopBinding $scope,int $actor,int $limit,string $action,string $missing):array|\WP_Error {
  if($limit<1||$limit>20)return NativeFailure::error('view_limit');$access=$this->authorizeWorkflow($scope,$actor,$action);return is_wp_error($access)?$access:NativeFailure::error($missing);
 }
 public function decideReview(array $request):array|\WP_Error{return NativeFailure::error('review_cas_and_checkpoint_required');}
 public function recordFulfillment(array $request):array|\WP_Error{return NativeFailure::error('fulfillment_receipt_allocation_join_required');}
 public function inspectFulfillment(ShopBinding $scope,int $actor,string $orderIdentity):ShipmentReadModel|\WP_Error {
  if(!preg_match('/^[a-f0-9]{64}$/D',$orderIdentity))return NativeFailure::error('order_identity');
  $access=$this->authorizeWorkflow($scope,$actor,'inspect_fulfillment');return is_wp_error($access)?$access:NativeFailure::error('fulfillment_receipt_allocation_join_required');
 }
 public function allocation(ShopBinding $scope,int $actor,array $request):array|\WP_Error {
  if(!NativePortContract::valid('allocation_request',$request))return NativeFailure::error('allocation_contract');
  $access=$this->authorizeWorkflow($scope,$actor,'inspect_allocations');return is_wp_error($access)?$access:NativeFailure::error('approved_allocation_proof_join_required');
 }
 public function recovery(ShopBinding $scope,int $actor,array $request):array|\WP_Error {
  if(!NativePortContract::valid('recovery_request',$request))return NativeFailure::error('recovery_contract');
  $access=$this->authorizeWorkflow($scope,$actor,'inspect_recovery');return is_wp_error($access)?$access:NativeFailure::error('independent_checkpoint_and_drain_required');
 }
 public function panelAccess(ShopBinding $scope,int $actor,array $context):array|\WP_Error{return NativePanelAccessAdapter::access($scope,$actor,$context);}
}
