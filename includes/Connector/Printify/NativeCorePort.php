<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
use DigiForgePrintifyConnector\Preparation\{NativeCorePortInterface,ShipmentReadModel};
use DigiForge\Core\Settings;
use DigiForge\PortalAccess\Owner;
use DigiForge\POD\PrintifyProductPipeline;

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
  $access=NativeAuthorityAdapter::mutationScope($b);if(is_wp_error($access))return $access;
  if($request['action']==='RECEIPT_READ')return $this->readHeldUncertainty($b,$request['duplicate_key'],$request['data']);
  if($request['action']==='AUTHORIZE' && count($request['evidence_references'])===2
     && str_starts_with($c['logical_reference'],'product-source:')){
   return $this->checkLocalProductAssociation($access,$b,$c['logical_reference'],$request['evidence_references']);
  }
  if($request['action']==='AUTHORIZE' && count($request['evidence_references'])===1){
   $plan=$this->checkLocalPlanApproval($access,$b,$request['evidence_references'][0]);
   if(is_wp_error($plan))return $plan;
  }
  if(in_array($request['action'],['REGISTER','CLAIM'],true)&&str_starts_with($c['logical_reference'],'product-source:')){
   $locator=self::productSourceLocator($c['logical_reference']);
   if($locator===null||count($request['evidence_references'])!==2||$request['data']!==[]){
    return NativeFailure::error('canonical_product_intent_contract');
   }
   [$plan,$review]=$request['evidence_references'];
   return NativeBusinessMutationEvidence::checkCanonicalAdmission($access,$b,$request['duplicate_key'],$locator[0],$locator[1],$plan,$review);
  }
  // Core's existing option-backed claims are not the specified transactional native journal.
  // Never call ControlledExecutionTransaction::execute or trust caller receipts as a passive helper.
  return NativeFailure::error('journal_and_independent_checkpoint_required');
 }
 /**
  * Prepare local held evidence from a canonical product-update REGISTER request.
  * Separate from exchange(): this does not admit a mutation or issue a lease.
  */
 public function stageHeldProduct(array $request):array|\WP_Error {
  $candidate=self::heldProductBinding($request);
  if(is_wp_error($candidate))return $candidate;
  return (new NativeHeldJournal())->prepareScoped($candidate,$request['duplicate_key']);
 }
 /** Consume a local fence on the same canonical intent. */
 public function fenceHeldProduct(array $request,int $expectedRevision,string $expectedHead):array|\WP_Error {
  $candidate=self::heldProductBinding($request);
  if(is_wp_error($candidate))return $candidate;
  return (new NativeHeldJournal())->fenceScoped($candidate,$expectedRevision,$expectedHead);
 }
 private static function heldProductBinding(array $request):array|\WP_Error {
  $error=NativeFailure::error('held_product_identity');
  if(!function_exists('wp_get_environment_type')||wp_get_environment_type()!=='local'
     ||!NativePortContract::valid('mutation_request',$request)||$request['action']!=='REGISTER')return $error;
  $binding=$request['binding'];$context=$request['context'];$data=$request['data'];
  if($binding['environment']!=='test'||$binding['shop_key']!=='personalized_pod')return $error;
  foreach(['actor_id','authorization_revision','approval_reference','product_version','expected_product_version'] as $key){
   if($binding[$key]!==$context[$key])return $error;
  }
  $prepared=$data['prepared']??null;
  $wire=is_array($prepared)?($prepared['core_request']??null):null;
  if(count($data)!==1||!is_array($wire)||!\DigiForge\POD\PrintifyProductRequest::intact($wire)
     ||($prepared['scope_fingerprint']??null)!==$binding['scope_fingerprint']
     ||($prepared['external_execution_authorized']??null)!==false
     ||$wire['operation']!=='PRODUCT_UPDATE')return $error;
  $endpoint='/v1/shops/'.$binding['provider_shop_id'].'/products';
  if(preg_match('#^'.preg_quote($endpoint,'#').'/([A-Za-z0-9_-]{1,160})\.json$#D',$wire['endpoint'],$match)!==1
     ||$context['logical_reference']!==$match[1])return $error;
  try{
   $input=$wire['body'];
   if(array_key_exists('product_id',$input)||array_key_exists('known_variant_ids',$input))return $error;
   $input['product_id']=$match[1];
   if(isset($input['variants'])&&is_array($input['variants']))$input['known_variant_ids']=array_column($input['variants'],'id');
   $expected=\DigiForge\POD\PrintifyProductRequest::prepare('PRODUCT_UPDATE',$binding['provider_shop_id'],$input);
   if(!hash_equals(NativePortContract::hash($expected),NativePortContract::hash($wire)))return $error;
   $payload=NativePortContract::hash(array_intersect_key($wire,array_flip(['method','endpoint','body'])));
   $logical=$context['logical_reference'].':'.$context['product_version'];
   $duplicate=NativePortContract::hash([$wire['operation'],$binding['shop_key'],$binding['integration_id'],
    $binding['provider_shop_id'],$binding['environment'],$logical]);
   $operation=NativePortContract::hash(['DF_PRINTIFY_PREPARATION_V1',$duplicate,$payload]);
   $intent=NativePortContract::hash([$operation,$binding['scope_fingerprint'],$context,$prepared]);
   if(!hash_equals($request['duplicate_key'],$duplicate)||!hash_equals($binding['operation_id'],$operation)
      ||!hash_equals($binding['payload_fingerprint'],$payload)||!hash_equals($binding['intent_fingerprint'],$intent))return $error;
   return array_map(static fn($value)=>$value,$binding);
  }catch(\Throwable){return $error;}
 }
 /** Existing v1 references locate a protected association; they never attest its content. */
 private function checkLocalProductAssociation(ShopBinding $scope,array $binding,string $locator,array $references):\WP_Error {
  $ids=self::productSourceLocator($locator);
  if($ids===null)return NativeFailure::error('current_product_association_required');
  [$plan,$review]=$references;
  try{
   $association=NativeBusinessMutationAssociation::read($scope,$binding['actor_id'],$ids[0],$ids[1],$plan);
   if(is_wp_error($association))return NativeFailure::error('current_product_association_required');
   $source=$association['source'];
   if(!hash_equals($association['review_hash'],$review)
      ||!hash_equals($source['plan_policy_hash'],$binding['approval_reference'])
      ||!hash_equals($source['payload_fingerprint'],$binding['payload_fingerprint'])
      ||!hash_equals($source['scope_fingerprint'],$binding['scope_fingerprint'])){
    return NativeFailure::error('mutation_product_association_mismatch');
   }
   // A protected local product-version row is not the independent native revision.
   return NativeFailure::error('product_revision_authority_required');
  }catch(\Throwable){return NativeFailure::error('current_product_association_required');}
 }
 private static function productSourceLocator(string $locator):?array {
  if(preg_match('/^product-source:([1-9][0-9]{0,18}):([1-9][0-9]{0,18})$/D',$locator,$ids)!==1
     ||(string)(int)$ids[1]!==$ids[1]||(string)(int)$ids[2]!==$ids[2])return null;
  return [(int)$ids[1],(int)$ids[2]];
 }
 /** Validate submitted plan evidence without treating it as operational approval. */
 private function checkLocalPlanApproval(ShopBinding $scope,array $binding,string $reference):true|\WP_Error {
  try{
   $plan=(new PrintifyProductPipeline())->plan($reference);
   if(is_wp_error($plan))return NativeFailure::error('current_plan_approval_required');
   $request=$plan['request'];
   $approval=NativePlanEvidence::productPlan($scope,$binding['actor_id'],$reference);
   if(is_wp_error($approval))return NativeFailure::error('current_plan_approval_required');
   $wire=['method'=>$request['method'],'endpoint'=>$request['endpoint'],'body'=>$request['body']];
   if(!in_array($wire['method'],['POST','PUT','DELETE'],true)
      ||$approval['operation']!==$request['operation']
      ||!hash_equals($approval['request_fingerprint'],$request['fingerprint'])
      ||!hash_equals($approval['approval_hash'],$binding['approval_reference'])
      ||!hash_equals(NativePortContract::hash($wire),$binding['payload_fingerprint'])){
    return NativeFailure::error('mutation_plan_mismatch');
   }
   return true;
  }catch(\Throwable){return NativeFailure::error('current_plan_approval_required');}
 }
 /** A known held event can explain uncertainty but cannot supply a provider receipt. */
 private function readHeldUncertainty(array $binding,string $duplicateKey,array $data):\WP_Error {
  $reference=$data['receipt_reference']??null;
  if(count($data)!==1||!is_string($reference)||preg_match('/^[a-f0-9]{64}$/D',$reference)!==1)return NativeFailure::error('receipt_contract');
  try{
   $entry=(new NativeHeldJournal())->read($binding['operation_id'],$binding['shop_key'],$binding['actor_id']);
   if(is_wp_error($entry)||$entry['record']['state']!=='UNKNOWN'
      ||!hash_equals($entry['event_hash'],$reference)
      ||!hash_equals($entry['record']['candidate_key'],$duplicateKey)
      ||!hash_equals(NativePortContract::hash($entry['record']['binding']),NativePortContract::hash($binding))){
    return NativeFailure::error('receipt_unavailable');
   }
   $access=NativeAuthorityAdapter::mutationScope($binding);
   if(is_wp_error($access))return $access;
   return new \WP_Error('printify_native_held_outcome_unknown','Exact local held event still requires reconciliation.',[
    'status'=>409,'outcome'=>'UNKNOWN','journal_revision'=>$entry['record']['revision'],
    'provider_receipt_verified'=>false,'network_request_attempted'=>null,
    'external_execution_authorized'=>false,'retry_permitted'=>false,
   ]);
  }catch(\Throwable){return NativeFailure::error('receipt_unavailable');}
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
 public function inspectWorkflows(ShopBinding $scope,int $actor,int $limit):array|\WP_Error{
  $access=$this->authorizeWorkflow($scope,$actor,'inspect_workflows');return is_wp_error($access)?$access:(new NativeOrderSourceJournal())->snapshot($scope,$actor,$limit);
 }
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
