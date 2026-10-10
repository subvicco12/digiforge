<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;

/** Local artifact receipts prove byte parity, not visual quality or supplier certification. */
final class RenderArtifactVerifier
{
 public function readArtifact(string $path):array|WP_Error {
  if($path===''||str_contains($path,'://')||str_contains($path,"\0")||is_link($path)||!is_file($path))return $this->error('invalid','A regular local image artifact is required.');
  $handle=@fopen($path,'rb');if($handle===false)return $this->error('unavailable','Local artifact cannot be read.');
  try{$before=fstat($handle);if(!is_array($before)||($before['mode']&0170000)!==0100000||$before['size']<1||$before['size']>16777216)return $this->error('invalid','Artifact size is outside the verification bound.');$bytes=stream_get_contents($handle,16777217);$after=fstat($handle);}finally{fclose($handle);}
  if(!is_string($bytes)||!is_array($after)||$before['size']!==strlen($bytes)||$before['size']!==$after['size']||$before['mtime']!==$after['mtime']||$before['ctime']!==$after['ctime'])return $this->error('uncertain','Artifact changed or could not be read completely.');
  $size=@getimagesizefromstring($bytes);if(!is_array($size)||!in_array($size[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG],true)||$size[0]<1||$size[1]<1||$size[0]*$size[1]>100000000)return $this->error('invalid','A bounded PNG or JPEG image is required.');
  return ['sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes),'width_px'=>$size[0],'height_px'=>$size[1],'mime'=>$size['mime']];
 }
 public function recordFromArtifacts(array $render,string $outputPath,string $previewPath):array|WP_Error {
  if(!current_user_can('manage_digiforge_pod'))return $this->error('reviewer_required','Authorized POD artifact reviewer required.');
  $output=$this->readArtifact($outputPath);if($output instanceof WP_Error)return $output;$preview=$this->readArtifact($previewPath);if($preview instanceof WP_Error)return $preview;
  if(!hash_equals($output['sha256'],$preview['sha256'])||!hash_equals((string)$render['output_sha256'],$output['sha256']))return $this->error('parity_failed','Actual output and buyer preview bytes must match.');
  $template=$this->template($render);if($template instanceof WP_Error)return $template;
  // One image cannot certify a multi-panel template. Require a future explicit panel artifact contract.
  $areas=$template['print_areas'];if(count($areas)!==1||$areas[0]['width_px']!==$output['width_px']||$areas[0]['height_px']!==$output['height_px'])return $this->error('geometry_failed','Actual artifact dimensions must match the single certified print area.');
  $receipt=['evidence_hash'=>$render['evidence_hash'],'template_sha256'=>$template['fingerprint'],'output'=>$output,'buyer_preview'=>$preview,'external_execution_authorized'=>false];
  global $wpdb;$key='digiforge_render_artifact_'.$render['evidence_hash'];$old=$this->read($key);if($old instanceof WP_Error)return $old;if($old!==null)return $old===$receipt?$old:$this->error('conflict','Immutable artifact receipt conflict.');
  $wpdb->last_error='';$wpdb->insert($wpdb->options,['option_name'=>$key,'option_value'=>wp_json_encode($receipt),'autoload'=>'no']);$saved=$this->read($key);if($saved instanceof WP_Error)return $saved;
  return $saved===$receipt?$saved:$this->error('confirmation_unavailable','Artifact receipt persistence could not be confirmed.');
 }
 public function assertCertified(array $render):array|WP_Error {
  $receipt=$this->read('digiforge_render_artifact_'.(string)($render['evidence_hash']??''));if($receipt instanceof WP_Error)return $receipt;if($receipt===null)return $this->error('receipt_required','Actual artifact byte verification is required before human review.');
  $template=$this->template($render);if($template instanceof WP_Error)return $template;
  if(($receipt['evidence_hash']??null)!==($render['evidence_hash']??null)||($receipt['template_sha256']??null)!==$template['fingerprint']||($receipt['output']['sha256']??null)!==($render['output_sha256']??null)||($receipt['buyer_preview']['sha256']??null)!==($render['output_sha256']??null))return $this->error('conflict','Stored artifact and render identities do not agree.');
  return $receipt;
 }
 private function template(array $render):array|WP_Error {
  if(!ctype_digit((string)($render['template_version']??''))||(int)$render['template_version']<1)return $this->error('template_invalid','Exact positive template version is required.');
  global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_production_templates().' WHERE template_id=%s AND template_version=%d',(string)($render['template_key']??''),(int)($render['template_version']??0)),ARRAY_A);
  if($wpdb->last_error!==''||!is_array($row)||$row['template_status']!=='VALIDATED')return $this->error('template_required','The exact stored VALIDATED template is required.');
  $row['variant_ids']=json_decode((string)$row['variant_ids'],true);$row['print_areas']=json_decode((string)$row['print_areas'],true);
  try{$template=ProductionTemplateContract::normalize($row);}catch(\Throwable $e){return $this->error('template_invalid','Stored template metadata is invalid.');}
  if(!hash_equals((string)$row['fingerprint'],$template['fingerprint'])||!hash_equals($template['fingerprint'],(string)($render['template_sha256']??'')))return $this->error('template_conflict','Exact normalized template fingerprint is required.');
  $wpdb->last_error='';$mapping=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_mappings().' WHERE id=%d AND state=%s AND approved_by>0 AND approved_at IS NOT NULL',(int)$render['provider_mapping_id'],'APPROVED'),ARRAY_A);
  if($wpdb->last_error!==''||!is_array($mapping)||$mapping['provider']!==$template['supplier']||!preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/D',(string)$mapping['provider_variant_key'],$m)||(int)$m[1]!==$template['provider_id']||!in_array((int)$m[2],$template['variant_ids'],true)||(string)$mapping['provider_product_key']!==(string)$template['provider_blueprint_id'])return $this->error('mapping_conflict','Approved catalog mapping and template supplier identity must agree.');
  $wpdb->last_error='';$shop=$wpdb->get_var($wpdb->prepare('SELECT shop_reference FROM '.Tables::orders().' WHERE id=%d',(int)$render['order_id']));if($wpdb->last_error!==''||!is_string($shop)||$shop==='')return $this->error('scope_unavailable','Order shop evidence is required.');
  $wpdb->last_error='';$approved=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::order_line_items()." li INNER JOIN ".Tables::personalization_submissions()." ps ON ps.order_line_item_id=li.id WHERE li.order_id=%d AND li.provider_mapping_id=%d AND li.validation_status='VALIDATED' AND ps.payload_hash=%s AND ps.review_status='APPROVED' AND ps.reviewed_by>0 AND ps.reviewed_at IS NOT NULL",(int)$render['order_id'],(int)$render['provider_mapping_id'],(string)$render['personalization_evidence_hash']));if($wpdb->last_error!==''||!is_numeric($approved)||(int)$approved<1)return $this->error('personalization_required','Current approved personalization and validated order lineage are required.');
  $scope=(new BusinessScopeRepository())->assertActiveOwnershipForMapping((int)$render['provider_mapping_id']);if($scope instanceof WP_Error)return $scope;
  if(($scope['business_key']??'')!==BusinessScope::DIGICRAFTIFY_GOODS||($scope['program_key']??'')!==BusinessScope::PERSONALIZED_POD)return $this->error('scope_unavailable','Only exact active personalized POD ownership is supported by this artifact contract.');
  $canonicalShop='personalized_pod';
  if($shop!==$canonicalShop){
   if(!ctype_digit($shop)||(int)$shop<1)return $this->error('scope_conflict','Order shop identity cannot be inferred from an arbitrary alias.');
   $wpdb->last_error='';$order=$wpdb->get_row($wpdb->prepare('SELECT channel,environment FROM '.Tables::orders().' WHERE id=%d',(int)$render['order_id']),ARRAY_A);if($wpdb->last_error!==''||!is_array($order)||$order['channel']!=='etsy')return $this->error('scope_unavailable','Exact order channel and environment are required.');
   $wpdb->last_error='';$ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM ".Tables::integrations()." WHERE provider='etsy' AND status='CONFIGURED' AND environment=%s",$order['environment']));if($wpdb->last_error!==''||!is_array($ids))return $this->error('scope_unavailable','Verified Etsy identity evidence is unavailable.');
   $verified=false;foreach($ids as $id){$identity=\DigiForge\Listings\EtsyVerifiedShopIdentity::resolve((int)$id,'DigiCraftifyGoods',(int)$shop);if(!($identity instanceof WP_Error)){$verified=true;break;}}
   if(!$verified)return $this->error('scope_conflict','External Etsy shop must match the verified identity of the approved business.');
  }
  $owner=(new TemplateCycleRepository())->assertOwner($canonicalShop,$template['fingerprint']);return $owner instanceof WP_Error?$owner:$template;
 }
 private function read(string $key):array|WP_Error|null {
  global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$key));if($wpdb->last_error!=='')return $this->error('unavailable','Artifact evidence store is unavailable.');if($raw===null)return null;$value=json_decode((string)$raw,true);return is_array($value)?$value:$this->error('corrupt','Artifact evidence is corrupt.');
 }
 private function error(string $code,string $message):WP_Error{return new WP_Error('render_artifact_'.$code,$message,['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
