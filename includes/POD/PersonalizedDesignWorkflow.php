<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use DigiForge\ProductFactory\AssetStorage;
use WP_Error;

/** Authenticated local artwork preparation. Never approves recipes or executes supplier calls. */
final class PersonalizedDesignWorkflow
{
 public function register():void {
  add_action('rest_api_init',function():void {
   foreach(['/pod/design-family/draft'=>'draft','/pod/customer-artwork'=>'ingest','/pod/customer-artwork/upload'=>'upload','/pod/customer-artwork/review'=>'review','/pod/customer-artwork/segment'=>'segmentUpload','/pod/customer-artwork/qa'=>'qa','/pod/visual-review/evidence'=>'visualEvidence','/pod/visual-review/assign'=>'visualAssignment','/pod/visual-review/decision'=>'visualDecision','/pod/visual-review/inspect'=>'visualInspection']as $route=>$method)register_rest_route('digiforge/v1',$route,['methods'=>'POST','permission_callback'=>static fn():bool=>current_user_can('manage_digiforge_pod'),'callback'=>function(\WP_REST_Request $request)use($method):\WP_REST_Response|WP_Error {if(strlen($request->get_body())>(in_array($method,['upload','segmentUpload','visualEvidence'],true)?8388608:32768))return self::error('bounded_request_required');$input=$request->get_json_params();if(!is_array($input))return self::error('object_required');$result=$this->$method($input);return $result instanceof WP_Error?$result:new \WP_REST_Response($result);}]);
  });
 }
 public function visualEvidence(array $input):array|WP_Error {
  if(!current_user_can('manage_digiforge_pod')||get_current_user_id()<1)return self::error('operator_required');if(($input['shop_key']??null)!=='personalized_pod')return self::error('scope_or_fields_invalid');$access=\DigiForge\Security\ShopAccess::authorize('personalized_pod','manage_digiforge_pod');if($access instanceof WP_Error)return $access;return (new CutoutVisualReviewRepository())->intake($input);
 }
 public function visualAssignment(array $input):array|WP_Error {
  $scope=$this->scope($input,['shop_key','case_key','case_sha256','reviewer_id']);if($scope instanceof WP_Error)return $scope;if(!is_string($input['case_key']??null)||!is_string($input['case_sha256']??null)||!is_int($input['reviewer_id']??null))return self::error('typed_input_required');return (new CutoutVisualReviewRepository())->assign($input['shop_key'],$input['case_key'],$input['case_sha256'],$input['reviewer_id']);
 }
 public function visualDecision(array $input):array|WP_Error {
  $scope=$this->scope($input,['shop_key','case_key','assignment_sha256','outcome','flags','annotations']);if($scope instanceof WP_Error)return $scope;if(!is_string($input['case_key']??null)||!is_string($input['assignment_sha256']??null)||!is_string($input['outcome']??null)||!is_array($input['flags']??null)||!is_array($input['annotations']??null))return self::error('typed_input_required');return (new CutoutVisualReviewRepository())->decide($input['shop_key'],$input['case_key'],$input['assignment_sha256'],$input['outcome'],$input['flags'],$input['annotations']);
 }
 public function visualInspection(array $input):array|WP_Error {
  $scope=$this->scope($input,['shop_key','case_key','render_id','position']);if($scope instanceof WP_Error)return $scope;if(!is_string($input['case_key']??null)||!is_int($input['render_id']??0)||!is_string($input['position']??''))return self::error('typed_input_required');return (new CutoutVisualReviewRepository())->inspect($input['shop_key'],$input['case_key'],$input['render_id']??0,$input['position']??'');
 }
 public function draft(array $input):array|WP_Error {
  $scope=$this->scope($input,['shop_key','template_key','template_version','family_id','settings','bitmap_font']);if($scope instanceof WP_Error)return $scope;
  if(!is_string($input['template_key']??null)||!is_int($input['template_version']??null)||!is_string($input['family_id']??null)||!is_array($input['settings']??null)||!is_array($input['bitmap_font']??[]))return self::error('typed_input_required');
  global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_production_templates().' WHERE template_id=%s AND template_version=%d AND template_status=%s',$input['template_key'],$input['template_version'],'VALIDATED'),ARRAY_A);if($wpdb->last_error!==''||!is_array($row))return self::error('stored_template_required');
  $owner=(new TemplateCycleRepository())->assertOwner($input['shop_key'],$row['fingerprint']);if($owner instanceof WP_Error)return $owner;
  $row['variant_ids']=json_decode($row['variant_ids'],true);$row['print_areas']=json_decode($row['print_areas'],true);
  try{$template=ProductionTemplateContract::normalize($row);if(!hash_equals($row['fingerprint'],$template['fingerprint']))return self::error('template_provenance_conflict');$current=(new ProductionTemplateRepository())->assertCurrentCatalogSnapshot($template);if($current instanceof WP_Error)return $current;return DesignFamilyLibrary::draft($template,$input['family_id'],$input['settings'],$input['bitmap_font']??[])+['template_sha256'=>$template['fingerprint']];}catch(\Throwable $e){return self::error('draft_unsupported',$e->getMessage());}
 }
 public function ingest(array $input):array|WP_Error {
  $scope=$this->scope($input,['shop_key','key','order_id','source_reference','mask_reference','rights','style','options']);if($scope instanceof WP_Error)return $scope;
  if(!is_string($input['key']??null)||!is_int($input['order_id']??null)||!is_array($input['rights']??null)||!is_string($input['style']??null)||!is_array($input['options']??null))return self::error('typed_input_required');
  $source=$this->path($input['source_reference']??null);if($source instanceof WP_Error)return $source;$mask=null;if(($input['mask_reference']??null)!==null){$mask=$this->path($input['mask_reference']);if($mask instanceof WP_Error)return $mask;}
  return (new CustomerCutoutRepository())->ingest($input['shop_key'],$input['key'],$input['order_id'],$source,$mask,$input['rights'],$input['style'],$input['options']);
 }
 public function qa(array $input):array|WP_Error {
  $scope=$this->scope($input,['shop_key','key']);if($scope instanceof WP_Error)return $scope;if(!is_string($input['key']??null))return self::error('typed_input_required');
  $repo=new CustomerCutoutRepository();$qa=$repo->visualQa($input['shop_key'],$input['key']);if($qa instanceof WP_Error)return $qa;$previews=$repo->visualPreviews($input['shop_key'],$input['key']);if($previews instanceof WP_Error)return $previews;
  foreach($qa['preview_sha256']??[]as $background=>$hash)if(!hash_equals($hash,hash('sha256',$previews[$background])))return self::error('preview_evidence_conflict');
  return ['visual_qa'=>$qa,'previews_base64'=>array_map('base64_encode',$previews),'commercial_certified'=>false,'supplier_certified'=>false];
 }
 public function review(array $input):array|WP_Error {
  $scope=$this->scope($input,['shop_key','key','review']);if($scope instanceof WP_Error)return $scope;if(!is_string($input['key']??null)||!is_array($input['review']??null))return self::error('typed_input_required');return (new CustomerCutoutRepository())->approve($input['shop_key'],$input['key'],$input['review']);
 }
 public function upload(array $input):array|WP_Error {
  $scope=$this->scope($input,['shop_key','key','order_id','source_base64','mask_base64','rights','style','options']);if($scope instanceof WP_Error)return $scope;
  if(!is_string($input['key']??null)||!is_int($input['order_id']??null)||!is_array($input['rights']??null)||!is_string($input['style']??null)||!is_array($input['options']??null))return self::error('typed_input_required');
  $paths=[];
  try{foreach(['source','mask']as $kind){$encoded=$input[$kind.'_base64']??null;if($kind==='mask'&&$encoded===null)continue;if(!is_string($encoded)||strlen($encoded)>6000000)return self::error('bounded_upload_required');$bytes=base64_decode($encoded,true);if(!is_string($bytes)||strlen($bytes)>4500000||base64_encode($bytes)!==$encoded)return self::error('canonical_upload_required');$path=tempnam(sys_get_temp_dir(),'df-design-upload-');if($path===false)return self::error('storage_unavailable');$paths[$kind]=$path;if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes))return self::error('storage_uncertain');}
   return (new CustomerCutoutRepository())->ingest($input['shop_key'],$input['key'],$input['order_id'],$paths['source'],$paths['mask']??null,$input['rights'],$input['style'],$input['options']);
  }finally{foreach($paths as $path)unlink($path);}
 }
 public function segmentUpload(array $input):array|WP_Error {
  $scope=$this->scope($input,['shop_key','key','order_id','source_base64','rights','style','options','selection']);if($scope instanceof WP_Error)return $scope;
  if(!is_string($input['key']??null)||!is_int($input['order_id']??null)||!is_array($input['rights']??null)||!is_string($input['style']??null)||!is_array($input['options']??null)||!is_array($input['selection']??null))return self::error('typed_input_required');
  $encoded=$input['source_base64']??null;if(!is_string($encoded)||strlen($encoded)>6000000)return self::error('bounded_upload_required');$bytes=base64_decode($encoded,true);if(!is_string($bytes)||strlen($bytes)>4500000||base64_encode($bytes)!==$encoded)return self::error('canonical_upload_required');
  $path=tempnam(sys_get_temp_dir(),'df-segment-upload-');if($path===false)return self::error('storage_unavailable');
  try{if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes))return self::error('storage_uncertain');return (new CustomerCutoutRepository())->segment($input['shop_key'],$input['key'],$input['order_id'],$path,$input['rights'],$input['style'],$input['options'],$input['selection']);}finally{unlink($path);}
 }
 private function path(mixed $reference):string|WP_Error {
  if(!is_string($reference)||strlen($reference)>512||!preg_match('~^digiforge/product-factory/[A-Za-z0-9_./-]+$~D',$reference)||array_intersect(explode('/',$reference),['.','..','']))return self::error('protected_upload_required');if(str_contains($reference,'/shop-')&&!str_contains($reference,'/shop-personalized_pod/'))return self::error('shop_conflict');$path=AssetStorage::absolutePath($reference);return $path??self::error('protected_upload_required');
 }
 private function scope(array $input,array $allowed):true|WP_Error {
  if(!current_user_can('manage_digiforge_pod')||get_current_user_id()<1)return self::error('operator_required','Authorized local artwork reviewer required.',403);if(($input['shop_key']??null)!=='personalized_pod'||array_diff(array_keys($input),$allowed))return self::error('scope_or_fields_invalid');return \DigiForge\Security\ShopAccess::authorize('personalized_pod','manage_digiforge_pod');
 }
 private static function error(string $code,string $message='Exact local design inputs and evidence required.',int $status=409):WP_Error {return new WP_Error('personalized_design_'.$code,$message,['status'=>$status,'external_execution_authorized'=>false,'retry_permitted'=>false]);}
}
