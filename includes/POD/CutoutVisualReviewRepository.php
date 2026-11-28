<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use DigiForge\Orders\Validator;
use DigiForge\ProductFactory\AssetStorage;
use DigiForge\Security\Logger;
use WP_Error;

/** Immutable local evidence and independent review; never legal/artistic/supplier certification. */
final class CutoutVisualReviewRepository
{
 public const VERSION='independent-cutout-review-v1';
 public static function lockName(string $shop,int $order):string {return 'df-vreview-'.substr(hash('sha256',$shop.'|'.$order),0,48);}
 public function intake(array $input):array|WP_Error {
  if(!current_user_can('manage_digiforge_pod')||get_current_user_id()<1)return self::error('operator_required');
  try{
   self::exact($input,['shop_key','case_key','cutout_key','source_sha256','subject_type','reference_base64','reference_sha256','reference_kind','rights','supersedes']);self::token($input['case_key']);self::token($input['cutout_key']);self::hash($input['source_sha256']);self::hash($input['reference_sha256']);
   if(!in_array($input['subject_type'],['dog','cat','human','multi_subject'],true)||!in_array($input['reference_kind'],['OWNER_SUPPLIED','SYNTHETIC_TEST_ONLY'],true)||!is_array($input['rights'])||($input['supersedes']!==null&&!is_string($input['supersedes'])))throw new \InvalidArgumentException('Typed evidence required.');
   $cutout=(new CustomerCutoutRepository())->read($input['shop_key'],$input['cutout_key']);if($cutout instanceof WP_Error)return $cutout;if($cutout['style']==='SOURCE_PHOTO')return self::error('alpha_cutout_required');
   $source=$cutout['segmentation']['original_source']??$cutout['source'];if($source['sha256']!==$input['source_sha256'])return self::error('source_conflict');
   if(!is_string($input['reference_base64'])||strlen($input['reference_base64'])>6000000)return self::error('bounded_reference_required');$bytes=base64_decode($input['reference_base64'],true);if(!is_string($bytes)||strlen($bytes)>4500000||base64_encode($bytes)!==$input['reference_base64']||hash('sha256',$bytes)!==$input['reference_sha256'])return self::error('reference_hash_conflict');
   $pixels=RasterArtworkCodec::decode($bytes);if($pixels['width_px']!==$source['width_px']||$pixels['height_px']!==$source['height_px'])return self::error('reference_geometry_conflict');
   $actor=$this->ingestionActor($cutout);if($actor instanceof WP_Error)return $actor;
   $checked=$this->rights($input['rights'],$source['sha256'],$input['reference_sha256'],$cutout['order_id'],$input['reference_kind'],$cutout['rights']['evidence_kind']);if($checked instanceof WP_Error)return $checked;
   $path=tempnam(sys_get_temp_dir(),'df-reference-');if($path===false)return self::error('storage_unavailable');try{if(file_put_contents($path,$bytes)!==strlen($bytes))return self::error('storage_uncertain');$metadata=(new RenderArtifactVerifier())->readArtifact($path);if($metadata instanceof WP_Error)return $metadata;$reference=(new RenderArtifactStore())->retain($path,$metadata,$input['shop_key']);}finally{unlink($path);}if($reference instanceof WP_Error)return $reference;
   return $this->locked($input['shop_key'],$cutout['order_id'],function()use($input,$cutout,$source,$reference,$actor):array|WP_Error{
    $request=$input;unset($request['reference_base64']);$inputHash=hash('sha256',Validator::canonicalJson($request));$old=$this->load($this->key($input['shop_key'],$input['case_key']));if($old instanceof WP_Error)return $old;if($old!==null)return ($old['input_sha256']??null)===$inputHash&&($old['created_by']??null)===get_current_user_id()?$this->read($input['shop_key'],$input['case_key']):self::error('idempotency_conflict');
    $revision=1;$parentSha=null;if($input['supersedes']!==null){self::token($input['supersedes']);$parent=$this->read($input['shop_key'],$input['supersedes']);if($parent instanceof WP_Error)return $parent;$decision=$this->decision($parent);if($decision instanceof WP_Error)return $decision;if($decision['outcome']!=='REVISE'||$parent['order_id']!==$cutout['order_id']||$parent['revision']>=8||($parent['cutout_sha256']===$cutout['sha256']&&$parent['reference']['sha256']===$reference['sha256']))return self::error('correction_required');$child=$this->load('df_vr_child_'.$parent['sha256']);if($child instanceof WP_Error)return $child;if($child!==null)return self::error('correction_replay');$revision=$parent['revision']+1;$parentSha=$parent['sha256'];}
    $headName=$this->headKey($input['shop_key'],$input['cutout_key']);$head=$this->load($headName);if($head instanceof WP_Error)return $head;if($head!==null&&($input['supersedes']===null||($head['case_key']??null)!==$input['supersedes']))return self::error('evidence_rebind_conflict');
    $qa=$this->qa($cutout,$reference);if($qa instanceof WP_Error)return $qa;
    $case=self::seal(['version'=>self::VERSION,'case_key'=>$input['case_key'],'shop_key'=>$input['shop_key'],'order_id'=>$cutout['order_id'],'cutout_key'=>$input['cutout_key'],'cutout_sha256'=>$cutout['sha256'],'source'=>$source,'reference'=>$reference,'reference_kind'=>$input['reference_kind'],'source_evidence_kind'=>$cutout['rights']['evidence_kind'],'subject_type'=>$input['subject_type'],'rights'=>$input['rights'],'qa'=>$qa,'ingested_by'=>$actor,'created_by'=>get_current_user_id(),'created_at'=>gmdate(DATE_ATOM),'input_sha256'=>$inputHash,'revision'=>$revision,'supersedes'=>$input['supersedes'],'parent_sha256'=>$parentSha,'state'=>'REVIEW_REQUIRED','commercial_certified'=>false,'supplier_certified'=>false,'external_execution_authorized'=>false]);
    $saved=$this->put($this->key($input['shop_key'],$input['case_key']),$case);if($saved instanceof WP_Error)return $saved;if(!Logger::write('cutout_visual_evidence_intake',['sha256'=>$case['sha256'],'external_execution_authorized'=>false],'cutout_visual_review',$case['sha256']))return self::error('audit_uncertain');
    if($parentSha!==null){$saved=$this->put('df_vr_child_'.$parentSha,['case_key'=>$case['case_key'],'sha256'=>$case['sha256']]);if($saved instanceof WP_Error)return $saved;}
    global $wpdb;$next=Validator::canonicalJson(['case_key'=>$case['case_key'],'sha256'=>$case['sha256']]);if($head===null)$saved=$this->put($headName,json_decode($next,true));else{$wpdb->last_error='';$count=$wpdb->query($wpdb->prepare('UPDATE '.$wpdb->options.' SET option_value=%s WHERE option_name=%s AND option_value=%s',$next,$headName,Validator::canonicalJson($head)));$saved=$count===1&&$wpdb->last_error===''?true:self::error('head_conflict');}return $saved instanceof WP_Error?$saved:$case;
   });
  }catch(\Throwable){return self::error('invalid_evidence');}
 }
 public function assign(string $shop,string $key,string $expected,int $reviewer):array|WP_Error {
  if(!current_user_can('manage_digiforge_pod')||get_current_user_id()<1)return self::error('operator_required');
  $case=$this->read($shop,$key);if($case instanceof WP_Error)return $case;
  return $this->locked($shop,$case['order_id'],function()use($shop,$key,$expected,$reviewer):array|WP_Error{
   $case=$this->read($shop,$key);if($case instanceof WP_Error)return $case;if($case['sha256']!==$expected||$case['created_by']!==get_current_user_id()||in_array($reviewer,[$case['created_by'],$case['ingested_by']],true)||$reviewer<1||!user_can($reviewer,'manage_digiforge_pod'))return self::error('independent_reviewer_required');
   $assignment=self::seal(['case_sha256'=>$case['sha256'],'reviewer_id'=>$reviewer,'assigned_by'=>get_current_user_id(),'assigned_at'=>gmdate(DATE_ATOM)]);$saved=$this->put('df_vr_assignment_'.$case['sha256'],$assignment,false);if($saved instanceof WP_Error)return $saved;return Logger::write('cutout_visual_assigned',['sha256'=>$assignment['sha256']],'cutout_visual_review',$case['sha256'])?$assignment:self::error('audit_uncertain');
  });
 }
 public function decide(string $shop,string $key,string $assignmentHash,string $outcome,array $flags,array $annotations):array|WP_Error {
  if(!current_user_can('manage_digiforge_pod')||get_current_user_id()<1)return self::error('operator_required');
  $case=$this->read($shop,$key);if($case instanceof WP_Error)return $case;
  return $this->locked($shop,$case['order_id'],function()use($shop,$key,$assignmentHash,$outcome,$flags,$annotations):array|WP_Error{
   $case=$this->read($shop,$key);if($case instanceof WP_Error)return $case;$assignment=$this->assignment($case);if($assignment instanceof WP_Error)return $assignment;
   if($assignment['sha256']!==$assignmentHash||$assignment['reviewer_id']!==get_current_user_id()||!in_array($outcome,['APPROVE','REJECT','REVISE'],true))return self::error('assigned_reviewer_required');
   $cutout=(new CustomerCutoutRepository())->read($shop,$case['cutout_key']);if($cutout instanceof WP_Error)return $cutout;
   try{self::exact($flags,(new CustomerCutoutRepository())->requiredReviewFlags($cutout));foreach($flags as $flag)if(!is_bool($flag))throw new \InvalidArgumentException();if(count($annotations)>32)throw new \InvalidArgumentException();foreach($annotations as $a){self::exact($a,['defect','severity','x','y','width','height','comment']);if(!array_key_exists($a['defect'],$flags)||!in_array($a['severity'],['CRITICAL','MINOR'],true)||!is_string($a['comment'])||strlen($a['comment'])>1000)throw new \InvalidArgumentException();foreach(['x','y','width','height']as $f)if(!is_int($a[$f])||$a[$f]<0||$a[$f]>10000)throw new \InvalidArgumentException();if($a['width']<1||$a['height']<1||$a['x']+$a['width']>$case['source']['width_px']||$a['y']+$a['height']>$case['source']['height_px'])throw new \InvalidArgumentException();}
   }catch(\Throwable){return self::error('review_contract');}
   if($outcome==='APPROVE'&&(in_array(true,$flags,true)||$annotations!==[]||$case['qa']['reference']['critical_defects']!==[]))return self::error('critical_defect_or_unresolved_annotation');
   $decision=self::seal(['case_sha256'=>$case['sha256'],'assignment_sha256'=>$assignmentHash,'qa_sha256'=>$case['qa']['sha256'],'outcome'=>$outcome,'flags'=>$flags,'annotations'=>$annotations,'reviewer_id'=>get_current_user_id(),'decided_at'=>gmdate(DATE_ATOM),'commercial_certified'=>false,'supplier_certified'=>false]);$saved=$this->put('df_vr_decision_'.$case['sha256'],$decision,false);if($saved instanceof WP_Error)return $saved;
   return Logger::write('cutout_visual_decided',['sha256'=>$decision['sha256'],'outcome'=>$outcome],'cutout_visual_review',$case['sha256'])?$decision:self::error('audit_uncertain');
  });
 }
 public function read(string $shop,string $key):array|WP_Error {
  if(!current_user_can('manage_digiforge_pod')||get_current_user_id()<1)return self::error('operator_required');
  return $this->readVerified($shop,$key);
 }
 private function readVerified(string $shop,string $key):array|WP_Error {
  try{self::token($key);$case=$this->load($this->key($shop,$key));if($case instanceof WP_Error)return $case;if($case===null||!self::sealed($case)||($case['version']??null)!==self::VERSION||($case['shop_key']??null)!==$shop||($case['case_key']??null)!==$key)return self::error('case_required');
   $cutout=(new CustomerCutoutRepository())->read($shop,$case['cutout_key']);if($cutout instanceof WP_Error)return $cutout;$source=$cutout['segmentation']['original_source']??$cutout['source'];if($case['cutout_sha256']!==$cutout['sha256']||$case['order_id']!==$cutout['order_id']||Validator::canonicalJson($source)!==Validator::canonicalJson($case['source'])||$case['ingested_by']!==$this->ingestionActor($cutout))return self::error('stale_source');
   foreach(['source','reference']as $field){$verified=(new RenderArtifactStore())->verify($case[$field]);if($verified instanceof WP_Error)return $verified;if(!str_contains($verified['storage_reference'],'/shop-'.$shop.'/'))return self::error('shop_conflict');}
   $rights=$this->rights($case['rights'],$source['sha256'],$case['reference']['sha256'],$case['order_id'],$case['reference_kind'],$cutout['rights']['evidence_kind']);if($rights instanceof WP_Error)return $rights;$qa=$this->qa($cutout,$case['reference']);if($qa instanceof WP_Error)return $qa;if(Validator::canonicalJson($qa)!==Validator::canonicalJson($case['qa'])||!$this->audited('cutout_visual_evidence_intake',$case['sha256'],$case['sha256']))return self::error('evidence_changed');return $case;
  }catch(\Throwable){return self::error('evidence_changed');}
 }
 public function inspect(string $shop,string $key,int $renderId=0,string $position=''):array|WP_Error {
  $case=$this->read($shop,$key);if($case instanceof WP_Error)return $case;$cutout=(new CustomerCutoutRepository())->read($shop,$case['cutout_key']);if($cutout instanceof WP_Error)return $cutout;
  try{$productPreview=null;if($renderId!==0){if($renderId<1||strlen($position)>80)return self::error('render_scope_required');global $wpdb;$wpdb->last_error='';$render=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_render_evidence().' WHERE id=%d',$renderId),ARRAY_A);if($wpdb->last_error!==''||!is_array($render)||(int)$render['order_id']!==$case['order_id'])return self::error('render_scope_required');$receipt=(new RenderArtifactVerifier())->assertCertified($render);if($receipt instanceof WP_Error)return $receipt;if(($receipt['deterministic_personalization_verified']??null)!==true)return self::error('deterministic_preview_required');$productPreview=(new DeterministicRenderWorkflow())->preview($renderId,$position);if($productPreview instanceof WP_Error)return $productPreview;
    $wpdb->last_error='';$payloads=$wpdb->get_col($wpdb->prepare('SELECT ps.canonical_payload FROM '.Tables::personalization_submissions().' ps INNER JOIN '.Tables::order_line_items().' li ON li.id=ps.order_line_item_id WHERE li.order_id=%d AND li.provider_mapping_id=%d AND ps.payload_hash=%s AND ps.review_status=%s',$case['order_id'],(int)$render['provider_mapping_id'],$render['personalization_evidence_hash'],'APPROVED'));
    if($wpdb->last_error!==''||!is_array($payloads)||count($payloads)!==1)return self::error('render_scope_required');$payload=json_decode($payloads[0],true);$recipe=$this->load('digiforge_render_recipe_'.$render['template_sha256']);if($recipe instanceof WP_Error)return $recipe;$matched=false;foreach($recipe['recipe']['panels'][$productPreview['position']]['slots']??[]as $slot)if($slot['type']==='image'&&(($slot['cutout']??false)||($slot['reviewed_asset']??false))&&($payload[$slot['field']]??null)===$cutout['result']['storage_reference'])$matched=true;if(!$matched)return self::error('render_cutout_mismatch');
   }
   $images=['original'=>$this->bytes($case['source']),'mask'=>$this->bytes($cutout['mask']),'reference'=>$this->bytes($case['reference']),'final_cutout'=>$this->bytes($cutout['result'])]+CutoutVisualQa::previews($this->bytes($cutout['result']));$assignmentRaw=$this->load('df_vr_assignment_'.$case['sha256']);if($assignmentRaw instanceof WP_Error)return $assignmentRaw;$assignment=$assignmentRaw===null?null:$this->assignment($case);if($assignment instanceof WP_Error)return $assignment;$decisionRaw=$this->load('df_vr_decision_'.$case['sha256']);if($decisionRaw instanceof WP_Error)return $decisionRaw;$decision=$decisionRaw===null?null:$this->decision($case);if($decision instanceof WP_Error)return $decision;return ['case'=>$case,'qa'=>$case['qa'],'assignment'=>$assignment instanceof WP_Error?null:$assignment,'decision'=>$decision instanceof WP_Error?null:$decision,'previews_base64'=>array_map('base64_encode',$images),'product_preview'=>$productPreview,'product_preview_state'=>$productPreview===null?'REQUIRES_APPROVED_CANONICAL_RENDER':'VERIFIED_CANONICAL_LOCAL_PREVIEW','commercial_certified'=>false,'supplier_certified'=>false];}catch(\Throwable){return self::error('preview_unavailable');}
 }
 public function assertApproved(array $cutout):array|WP_Error {
  $head=$this->load($this->headKey($cutout['shop_key'],$cutout['ingestion_key']));if($head instanceof WP_Error)return $head;if($head===null)return self::error('independent_review_required');$case=$this->readVerified($cutout['shop_key'],$head['case_key']);if($case instanceof WP_Error)return $case;if($case['sha256']!==$head['sha256']||$case['cutout_sha256']!==$cutout['sha256'])return self::error('stale_case');$decision=$this->decision($case);if($decision instanceof WP_Error)return $decision;
  return $decision['outcome']==='APPROVE'&&$decision['qa_sha256']===$case['qa']['sha256']&&!in_array(true,$decision['flags'],true)&&$decision['annotations']===[]&&$case['qa']['reference']['critical_defects']===[]?$decision:self::error('independent_approval_required');
 }
 private function assignment(array $case):array|WP_Error {
  $a=$this->load('df_vr_assignment_'.$case['sha256']);if($a instanceof WP_Error)return $a;if($a===null||!self::sealed($a)||($a['case_sha256']??null)!==$case['sha256']||!is_int($a['reviewer_id']??null)||in_array($a['reviewer_id'],[$case['created_by'],$case['ingested_by']],true)||($a['assigned_by']??null)!==$case['created_by']||!user_can($a['reviewer_id'],'manage_digiforge_pod')||!$this->audited('cutout_visual_assigned',$case['sha256'],$a['sha256']))return self::error('assignment_required');return $a;
 }
 private function decision(array $case):array|WP_Error {
  $a=$this->assignment($case);if($a instanceof WP_Error)return $a;$d=$this->load('df_vr_decision_'.$case['sha256']);if($d instanceof WP_Error)return $d;if($d===null||!self::sealed($d)||($d['case_sha256']??null)!==$case['sha256']||($d['assignment_sha256']??null)!==$a['sha256']||($d['reviewer_id']??null)!==$a['reviewer_id']||!$this->audited('cutout_visual_decided',$case['sha256'],$d['sha256']))return self::error('decision_required');return $d;
 }
 private function qa(array $cutout,array $reference):array|WP_Error {
  try{$actual=(new CustomerCutoutRepository())->visualQa($cutout['shop_key'],$cutout['ingestion_key']);if($actual instanceof WP_Error)return $actual;return self::seal(['version'=>self::VERSION,'cutout'=>$actual,'reference'=>CutoutVisualQa::measure($this->bytes($reference)),'comparison'=>CutoutVisualQa::measure($this->bytes($cutout['mask']),$this->bytes($reference)),'reference_status'=>'SUPPLIED_UNVERIFIED','commercial_certified'=>false]);}catch(\Throwable){return self::error('qa_failed');}
 }
 private function rights(array $rights,string $source,string $reference,int $order,string $kind,string $sourceKind):true|WP_Error {
  try{self::exact($rights,['source_rights','source_consent','reference_rights','reference_consent']);foreach($rights as $name=>$document){self::exact($document,['storage_reference','sha256']);self::hash($document['sha256']);$ref=$document['storage_reference'];if(!is_string($ref)||!preg_match('~^digiforge/product-factory/pod-review-evidence/shop-personalized_pod/[A-Za-z0-9_-]{1,100}\.json$~D',$ref))throw new \InvalidArgumentException();$path=AssetStorage::absolutePath($ref);if($path===null||!is_file($path)||filesize($path)<1||filesize($path)>65536)throw new \InvalidArgumentException();$body=@file_get_contents($path,false,null,0,65537);if(!is_string($body)||strlen($body)>65536)throw new \InvalidArgumentException();if(!hash_equals($document['sha256'],hash('sha256',$body)))throw new \InvalidArgumentException();$proof=json_decode($body,true,16,JSON_THROW_ON_ERROR);self::exact($proof,['schema','shop_key','order_id','asset_sha256','kind','local_review_permitted','evidence_kind','declaration']);if($proof['schema']!==1||$proof['shop_key']!=='personalized_pod'||$proof['order_id']!==$order||$proof['asset_sha256']!==(str_starts_with($name,'source')?$source:$reference)||$proof['kind']!==$name||$proof['local_review_permitted']!==true||$proof['evidence_kind']!==(str_starts_with($name,'source')?$sourceKind:$kind)||!is_string($proof['declaration'])||strlen($proof['declaration'])<1||strlen($proof['declaration'])>2000)throw new \InvalidArgumentException();}return true;}catch(\Throwable){return self::error('bound_rights_and_consent_required');}
 }
 private function ingestionActor(array $record):int|WP_Error {
  global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT actor_id,context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s','customer_cutout_ingested','customer_cutout',$record['sha256']),ARRAY_A);if($wpdb->last_error!==''||!is_array($rows))return self::error('ingestion_actor_required');$actors=[];foreach($rows as $r)if((json_decode($r['context'],true)['sha256']??null)===$record['sha256']&&(int)$r['actor_id']>0)$actors[(int)$r['actor_id']]=true;return count($actors)===1?array_key_first($actors):self::error('ingestion_actor_required');
 }
 private function audited(string $event,string $object,string $hash):bool {
  global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s',$event,'cutout_visual_review',$object));if($wpdb->last_error!==''||!is_array($rows))return false;foreach($rows as $row)if((json_decode($row,true)['sha256']??null)===$hash)return true;return false;
 }
 private function bytes(array $image):string {$path=AssetStorage::absolutePath($image['storage_reference']);if($path===null)throw new \InvalidArgumentException();$bytes=@file_get_contents($path,false,null,0,4500001);if(!is_string($bytes)||strlen($bytes)>4500000||hash('sha256',$bytes)!==$image['sha256'])throw new \InvalidArgumentException();return $bytes;}
 private function locked(string $shop,int $order,callable $callback):array|WP_Error {
  global $wpdb;$name=self::lockName($shop,$order);$wpdb->last_error='';$ok=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$name));if($wpdb->last_error!==''||(string)$ok!=='1')return self::error('review_busy');
  $transaction=false;$result=self::error('transaction_unavailable');
  try{
   $wpdb->last_error='';$engines=$wpdb->get_col($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)',$wpdb->options,Tables::audit_log()));
   if($wpdb->last_error!==''||!is_array($engines)||count($engines)!==2||array_filter($engines,static fn($engine):bool=>strtoupper((string)$engine)!=='INNODB'))return self::error('transactional_storage_required');
   if($wpdb->query('START TRANSACTION')===false)return self::error('transaction_unavailable');$transaction=true;
   try{$result=$callback();}catch(\Throwable){$result=self::error('review_failed');}
   if($result instanceof WP_Error){if($wpdb->query('ROLLBACK')===false)$result=self::error('rollback_uncertain');}
   elseif($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');$result=self::error('commit_uncertain');}
   $transaction=false;
  }finally{if($transaction)$wpdb->query('ROLLBACK');$wpdb->last_error='';$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));}
  return $wpdb->last_error===''&&(string)$released==='1'?$result:self::error('release_uncertain');
 }
 private function key(string $shop,string $key):string {return 'df_vr_case_'.hash('sha256',$shop.'|'.$key);}
 private function headKey(string $shop,string $key):string {return 'df_vr_head_'.hash('sha256',$shop.'|'.$key);}
 private function load(string $key):array|WP_Error|null {global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$key));if($wpdb->last_error!=='')return self::error('store_unavailable');if($raw===null)return null;if(!is_string($raw)||strlen($raw)>65536)return self::error('store_corrupt');$record=json_decode($raw,true);return is_array($record)?$record:self::error('store_corrupt');}
 private function put(string $key,array $record,bool $idempotent=true):array|WP_Error {global $wpdb;$old=$this->load($key);if($old instanceof WP_Error)return $old;if($old!==null)return $idempotent&&Validator::canonicalJson($record)===Validator::canonicalJson($old)?$old:self::error('immutable_replay');$json=Validator::canonicalJson($record);if(strlen($json)>65536)return self::error('evidence_bound');$wpdb->last_error='';$wpdb->insert($wpdb->options,['option_name'=>$key,'option_value'=>$json,'autoload'=>'no']);$saved=$this->load($key);return is_array($saved)&&Validator::canonicalJson($saved)===$json?$saved:self::error('persistence_uncertain');}
 private static function exact(mixed $input,array $keys):void {if(!is_array($input)||count($input)!==count($keys)||array_diff(array_keys($input),$keys))throw new \InvalidArgumentException();}
 private static function token(mixed $s):void {if(!is_string($s)||!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$s))throw new \InvalidArgumentException();}
 private static function hash(mixed $s):void {if(!is_string($s)||!preg_match('/^[a-f0-9]{64}$/D',$s))throw new \InvalidArgumentException();}
 private static function seal(array $value):array {$value['sha256']=hash('sha256',Validator::canonicalJson($value));return $value;}
 private static function sealed(array $value):bool {$hash=$value['sha256']??null;unset($value['sha256']);return is_string($hash)&&preg_match('/^[a-f0-9]{64}$/D',$hash)&&hash_equals($hash,hash('sha256',Validator::canonicalJson($value)));}
 private static function error(string $code):WP_Error {return new WP_Error('cutout_visual_review_'.$code,'Current shop-scoped evidence and independent review required.',['status'=>409,'external_execution_authorized'=>false,'commercial_certified'=>false,'supplier_certified'=>false]);}
}
