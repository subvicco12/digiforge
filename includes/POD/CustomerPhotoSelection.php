<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use DigiForge\Orders\{Repository as Orders,Validator};
use DigiForge\Security\{Logger,ShopAccess};
use WP_Error;

/** Canonical local photo-input selections. No provider permits, production approval or silent supersession. */
final class CustomerPhotoSelection
{
 public function operatorView(string $session):array|WP_Error {
  if(!self::operator())return self::error('operator_required',403);$editor=new CustomerEditorRepository();$v=$editor->photoHead($session);if($v instanceof WP_Error)return $v;$snapshot=$editor->photoSnapshot($session,$v['sha256'],true);if($snapshot instanceof WP_Error)return $snapshot;$status=$this->status($session);if($status instanceof WP_Error)return $status;
  $photos=[];foreach($snapshot['slots']as $key=>$slot){$path=\DigiForge\ProductFactory\AssetStorage::absolutePath($snapshot['payload'][$key]);if($path===null)return self::error('photo_bytes_required');$bytes=file_get_contents($path);if(!is_string($bytes)||!hash_equals($slot['result_sha256'],hash('sha256',$bytes)))return self::error('photo_bytes_changed');$photos[]=['field'=>$key,'sha256'=>$slot['result_sha256'],'base64'=>base64_encode($bytes)];}
  global $wpdb;$wpdb->last_error='';$mapping=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_mappings().' WHERE id=%d',$snapshot['provider_mapping_id']),ARRAY_A);if($wpdb->last_error!==''||!is_array($mapping))return self::error('mapping_required');$templates=$wpdb->get_results($wpdb->prepare('SELECT template_id,template_version,print_areas FROM '.Tables::pod_production_templates().' WHERE template_status=%s AND supplier=%s AND provider_blueprint_id=%d','VALIDATED',$mapping['provider'],(int)$mapping['provider_product_key']),ARRAY_A);if($wpdb->last_error!==''||!is_array($templates))return self::error('template_read_failed');
  $preview=null;if(is_array($status['render']??null)){$preview=(new DeterministicRenderWorkflow())->preview($status['render']['render_id'],$status['render']['panel']);if($preview instanceof WP_Error)$preview=null;}
  $renderState='NOT_PREPARED';if(is_array($status['render']??null)){$wpdb->last_error='';$renderState=$wpdb->get_var($wpdb->prepare('SELECT review_status FROM '.Tables::pod_render_evidence().' WHERE id=%d',$status['render']['render_id']));if($wpdb->last_error!==''||!is_string($renderState))return self::error('render_read_failed');if($renderState==='APPROVED'){$review=$this->load('df_photo_render_review_'.$status['sha256']);if($review instanceof WP_Error||$review===null||!$this->auditProof('photo_render_reviewed','review_sha256',$review['sha256'],$snapshot['order_id'])||ShopAccess::authorize('personalized_pod','manage_digiforge_pod',$review['reviewer'])!==true)$renderState='HOLD_REVIEW_EVIDENCE';}}
  return ['editor_version'=>$v['sha256'],'order_id'=>$snapshot['order_id'],'line_item_id'=>$snapshot['line_item_id'],'selection'=>$status,'photos'=>$photos,'templates'=>$templates,'render_preview'=>$preview,'render_review_state'=>$renderState,'external_execution_authorized'=>false];
 }
 public function submit(string $session,string $version,int $expectedRevision):array|WP_Error {
  $snapshot=(new CustomerEditorRepository())->photoSnapshot($session,$version);if($snapshot instanceof WP_Error)return $snapshot;
  return $this->locked($snapshot['line_item_id'],function()use($session,$version,$expectedRevision):array|WP_Error {
   $s=(new CustomerEditorRepository())->photoSnapshot($session,$version);if($s instanceof WP_Error)return $s;$prior=$this->head($s['line_item_id']);if($prior instanceof WP_Error)return $prior;$revision=$prior['revision']??0;
   if($prior!==null&&in_array($revision,[$expectedRevision,$expectedRevision+1],true)&&Validator::canonicalJson($prior['snapshot'])===Validator::canonicalJson($s))return $this->readSelection($prior,false);
   if($revision!==$expectedRevision||$revision>=50)return self::error('revision_conflict');
   global $wpdb;$wpdb->last_error='';$submission=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::personalization_submissions().' WHERE order_line_item_id=%d AND personalization_schema_id=%d AND payload_hash=%s',$s['line_item_id'],$s['schema_id'],$s['payload_sha256']),ARRAY_A);if($wpdb->last_error!=='')return self::error('canonical_read_failed');
   // Existing schema enforces unique(line,schema,payload). Reuse identical canonical bytes,
   // but never inherit a prior selection review or render approval for a new editor version.
   if($submission!==null&&$submission['canonical_payload']!==Validator::canonicalJson($s['payload']))return self::error('canonical_conflict');
   if($submission===null)$submission=(new Orders())->createPersonalization(['order_line_item_id'=>$s['line_item_id'],'personalization_schema_id'=>$s['schema_id'],'payload'=>$s['payload']],'photo-input-'.$s['line_item_id'].'-'.($revision+1).'-'.$version);if($submission instanceof WP_Error)return $submission;
   if($submission['review_status']==='REJECTED')return self::error('canonical_rejection_requires_revision');
   $record=self::seal(['version'=>'canonical-photo-selection-v1','shop_key'=>'personalized_pod','revision'=>$revision+1,'parent_sha256'=>$prior['sha256']??null,'submission_id'=>(int)$submission['id'],'snapshot'=>$s,'submitted_by'=>get_current_user_id(),'submitted_at'=>gmdate(DATE_ATOM)]);
   $saved=$this->put('df_photo_selection_'.$record['sha256'],$record);if($saved instanceof WP_Error)return $saved;
   if(!Logger::write('photo_input_selected',['selection_sha256'=>$record['sha256'],'revision'=>$record['revision']],'photo_input',(string)$s['order_id']))return self::error('audit_uncertain');
   global $wpdb;$name='df_photo_head_'.$s['line_item_id'];$raw=Validator::canonicalJson(['sha256'=>$record['sha256']]);$wpdb->last_error='';
   if($prior===null){$ok=$wpdb->insert($wpdb->options,['option_name'=>$name,'option_value'=>$raw,'autoload'=>'no']);}else{$ok=$wpdb->query($wpdb->prepare('UPDATE '.$wpdb->options.' SET option_value=%s WHERE BINARY option_name=BINARY %s AND BINARY option_value=BINARY %s',$raw,$name,Validator::canonicalJson(['sha256'=>$prior['sha256']])));}
   if($ok!==1||$wpdb->last_error!==''||($this->head($s['line_item_id'])['sha256']??null)!==$record['sha256'])return self::error('selection_cas_uncertain');
   return $this->readSelection($record,false);
  });
 }
 public function review(string $session,string $hash,int $revision,string $decision,string $note):array|WP_Error {
  if(!self::operator()||!current_user_can('manage_digiforge_orders')||ShopAccess::authorize('personalized_pod','manage_digiforge_orders')!==true)return self::error('operator_required',403);
  $a=(new CustomerEditorRepository())->access($session,true);if($a instanceof WP_Error)return $a;
  return $this->locked($a['line_item_id'],function()use($session,$hash,$revision,$decision,$note,$a):array|WP_Error {
   $r=$this->current($session,$hash,$revision);if($r instanceof WP_Error)return $r;
   if(get_current_user_id()===$a['customer_user_id']||!in_array($decision,['APPROVED','REJECTED'],true)||trim($note)===''||strlen($note)>2000||preg_match('//u',$note)!==1)return self::error('independent_review_required');
   $old=$this->load('df_photo_review_'.$hash);if($old instanceof WP_Error)return $old;if($old!==null)return self::error('immutable_review');
   global $wpdb;$wpdb->last_error='';$submission=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::personalization_submissions().' WHERE id=%d',$r['submission_id']),ARRAY_A);if($wpdb->last_error!==''||!is_array($submission))return self::error('canonical_read_failed');
   if($submission['review_status']==='UNREVIEWED')$submission=(new Orders())->reviewPersonalization($r['submission_id'],$decision);
   elseif($decision==='APPROVED'&&$submission['review_status']!=='APPROVED')return self::error('canonical_rejection_requires_revision');
   if($submission instanceof WP_Error)return $submission;
   $review=self::seal(['selection_sha256'=>$hash,'submission_id'=>$r['submission_id'],'decision'=>$decision,'reviewed_by'=>get_current_user_id(),'note'=>$note,'reviewed_at'=>gmdate(DATE_ATOM),'canonical_review_status'=>$submission['review_status'],'canonical_reviewed_by'=>(int)$submission['reviewed_by'],'canonical_reviewed_at'=>$submission['reviewed_at']]);
   $saved=$this->put('df_photo_review_'.$hash,$review);if($saved instanceof WP_Error)return $saved;if(!Logger::write('photo_input_reviewed',['review_sha256'=>$review['sha256']],'photo_input',(string)$r['snapshot']['order_id']))return self::error('audit_uncertain');return $this->readSelection($r,false);
  });
 }
 /** Strict selected-photo consumer. Missing selection is never a legacy signal. */
 public function forOrder(string $shop,int $order,int $line,?int $expectedRevision=null):array|WP_Error|null {
  $r=$this->resolveForOrder($shop,$order,$line,$expectedRevision);return $r===null?self::error('selection_required'):$r;
 }
 /** v1 authoritative discriminator: null only for exactly admitted unmanaged legacy orders.
  * Historical markers are order-scoped, so ambiguity on another line holds the order.
  * A consumer expecting a positive selection revision can never fall back to legacy.
  */
 public function resolveForOrder(string $shop,int $order,int $line,?int $expectedRevision=null):array|WP_Error|null {
  if($shop!=='personalized_pod'||!self::operator())return self::error('operator_required',403);global $wpdb;$wpdb->last_error='';$found=$wpdb->get_var($wpdb->prepare('SELECT li.id FROM '.Tables::order_line_items().' li INNER JOIN '.Tables::orders().' o ON o.id=li.order_id WHERE li.id=%d AND o.id=%d AND '.\DigiForge\Listings\EtsyStoredShopScope::orderPredicate('personalized_pod','o'),$line,$order));if($wpdb->last_error!==''||(int)$found!==$line||$line<1||$order<1)return self::error('line_scope_required',403);
  $r=$this->head($line);if($r instanceof WP_Error)return $r;if($r===null){$managed=$this->managedOrder($order);if($managed instanceof WP_Error)return $managed;if($managed)return self::error('managed_selection_missing');return $expectedRevision===null||$expectedRevision===0?null:self::error('stale_selection');}
  if($r['snapshot']['order_id']!==$order||($expectedRevision!==null&&$r['revision']!==$expectedRevision))return self::error('stale_selection');return $this->readSelection($r,true);
 }
 private function managedOrder(int $order):bool|WP_Error {
  global $wpdb;$wpdb->last_error='';$n=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::audit_log()." WHERE event_type IN ('photo_input_selected','photo_editor_changed') AND object_type='photo_input' AND object_id=%s",(string)$order));if($wpdb->last_error!==''||!is_numeric($n))return self::error('selection_read_failed');return (int)$n>0;
 }
 public function forRender(array $render):array|WP_Error|null {
  global $wpdb;$wpdb->last_error='';$lines=$wpdb->get_results($wpdb->prepare('SELECT id,provider_mapping_id FROM '.Tables::order_line_items().' WHERE order_id=%d',(int)($render['order_id']??0)),ARRAY_A);if($wpdb->last_error!==''||!is_array($lines))return self::error('selection_read_failed');
  $managed=[];foreach($lines as $line){$head=$this->head((int)$line['id']);if($head instanceof WP_Error)return $head;if($head!==null){if((int)$line['provider_mapping_id']!==(int)($render['provider_mapping_id']??0))continue;$managed[]=$head;}}
  if(!$managed){$marker=$this->managedOrder((int)($render['order_id']??0));return $marker instanceof WP_Error?$marker:($marker?self::error('managed_selection_missing'):null);}
  if(count($managed)!==1)return self::error('ambiguous_selection');$r=$this->readSelection($managed[0],true);if($r instanceof WP_Error)return $r;
  return hash_equals($r['snapshot']['payload_sha256'],(string)($render['personalization_evidence_hash']??''))?$r:self::error('stale_payload');
 }
 public function status(string $session):array|WP_Error {
  $operator=self::operator();$a=(new CustomerEditorRepository())->access($session,$operator);if($a instanceof WP_Error)return $a;$r=$this->head($a['line_item_id']);if($r instanceof WP_Error)return $r;if($r===null)return ['revision'=>0,'state'=>'NOT_SUBMITTED'];
  $read=$this->readSelection($r,false);if(is_array($read)&&$read['review_state']==='APPROVED')$read=$this->readSelection($r,true);if($read instanceof WP_Error)return ['revision'=>$r['revision'],'sha256'=>$r['sha256'],'state'=>'STALE'];
  $binding=$this->load('df_photo_render_'.$r['sha256']);if($binding instanceof WP_Error)return $binding;return ['revision'=>$r['revision'],'sha256'=>$r['sha256'],'state'=>$read['review_state'],'submission_id'=>$r['submission_id'],'editor_version'=>$r['snapshot']['editor_version'],'render'=>$binding];
 }
 /** Explicitly reviewed image-only layout preset; never invents safe zones or text placement. */
 public function approvePhotoLayout(string $session,string $hash,int $revision,string $template,int $version,string $note):array|WP_Error {
  if(!self::operator()||trim($note)===''||strlen($note)>2000)return self::error('layout_review_required');$r=$this->current($session,$hash,$revision,true);if($r instanceof WP_Error)return $r;
  return $this->locked($r['snapshot']['line_item_id'],function()use($session,$hash,$revision,$template,$version,$note):array|WP_Error {
   $r=$this->current($session,$hash,$revision,true);if($r instanceof WP_Error)return $r;$s=$r['snapshot'];$keys=array_keys($s['slots']);$n=count($keys);if(!in_array($n,[1,2,3,4],true)||array_diff(array_keys($s['payload']),$keys))return self::error('reviewed_photo_text_recipe_required');
   global $wpdb;$wpdb->last_error='';$fingerprint=$wpdb->get_var($wpdb->prepare('SELECT fingerprint FROM '.Tables::pod_production_templates().' WHERE template_id=%s AND template_version=%d',$template,$version));if($wpdb->last_error!==''||!is_string($fingerprint))return self::error('template_required');
   $t=(new RenderArtifactVerifier())->certifiedTemplate(['order_id'=>$s['order_id'],'provider_mapping_id'=>$s['provider_mapping_id'],'template_key'=>$template,'template_version'=>(string)$version,'template_sha256'=>$fingerprint,'personalization_evidence_hash'=>$s['payload_sha256']]);if($t instanceof WP_Error)return $t;
   if(count($t['print_areas'])!==1||!in_array($t['personalization_engine'],['SINGLE_PHOTO','PHOTO_COLLAGE','MULTI_PHOTO'],true))return self::error('explicit_photo_layout_required');if(!in_array($n,['SINGLE_PHOTO'=>[1],'PHOTO_COLLAGE'=>[4],'MULTI_PHOTO'=>[2,3,4]][$t['personalization_engine']],true))return self::error('photo_family_slot_count_conflict');$area=$t['print_areas'][0];$qa=ArtworkGeometryQa::policy($area);if($qa['state']!=='VERIFIED_LOCAL_GEOMETRY'||$qa['bleed_state']!=='VERIFIED_LOCAL_BLEED_GEOMETRY')return self::error('reviewed_geometry_required');
   $safe=$qa['safe_zone'];$cols=$n===1?1:2;$rows=(int)ceil($n/$cols);$w=intdiv($safe['width'],$cols);$h=intdiv($safe['height'],$rows);if($w<1||$h<1)return self::error('photo_layout_too_small');$slots=[];
   foreach($keys as $i=>$field){$slot=['type'=>'image','field'=>$field,'x'=>$safe['x']+($i%$cols)*$w,'y'=>$safe['y']+intdiv($i,$cols)*$h,'width'=>$w,'height'=>$h];try{ArtworkGeometryQa::placement($qa,$slot);}catch(\Throwable){return self::error('photo_layout_intersects_exclusions');}$slots[]=$slot;}
   $recipe=['engine'=>$t['personalization_engine'],'background'=>'#ffffff','slots'=>$slots];$approved=(new DeterministicPersonalizationRenderer())->approveRecipe($template,$version,$recipe);if($approved instanceof WP_Error)return $approved;
   if(!Logger::write('photo_layout_reviewed',['template_sha256'=>$fingerprint,'recipe_sha256'=>$approved['recipe_sha256'],'note'=>$note],'photo_input',(string)$s['order_id']))return self::error('audit_uncertain');return $approved;
  });
 }
 public function prepare(string $session,string $hash,int $revision,string $template,int $version):array|WP_Error {
  if(!self::operator())return self::error('operator_required',403);$r=$this->current($session,$hash,$revision,true);if($r instanceof WP_Error)return $r;
  $render=(new DeterministicRenderWorkflow())->prepare(['shop_key'=>'personalized_pod','decision'=>'PREPARE_LOCAL_ARTIFACTS','submission_id'=>$r['submission_id'],'template_key'=>$template,'template_version'=>$version,'artifact_format'=>'png']);if($render instanceof WP_Error)return $render;
  $again=$this->current($session,$hash,$revision,true);if($again instanceof WP_Error)return $again;
  $binding=self::seal(['selection_sha256'=>$hash,'render_id'=>(int)$render['id'],'panel'=>$this->panel($render),'template'=>$template,'template_version'=>$version]);$put=$this->put('df_photo_render_'.$hash,$binding);return $put instanceof WP_Error?$put:$render;
 }
 public function approveRender(string $session,string $hash,int $revision,string $note):array|WP_Error {
  if(!self::operator()||trim($note)===''||strlen($note)>2000)return self::error('independent_render_review_required');$r=$this->current($session,$hash,$revision,true);if($r instanceof WP_Error)return $r;
  return $this->locked($r['snapshot']['line_item_id'],function()use($session,$hash,$revision,$note):array|WP_Error {
   $r=$this->current($session,$hash,$revision,true);if($r instanceof WP_Error)return $r;if(get_current_user_id()===$r['snapshot']['customer_user_id'])return self::error('independent_render_review_required');
   $binding=$this->load('df_photo_render_'.$hash);if($binding instanceof WP_Error||$binding===null)return self::error('render_required');
   $approved=(new RenderEvidenceRepository())->approve($binding['render_id']);if($approved instanceof WP_Error)return $approved;
   $review=self::seal(['selection_sha256'=>$hash,'render_id'=>$binding['render_id'],'reviewer'=>get_current_user_id(),'reviewed_at'=>$approved['reviewed_at'],'note'=>$note]);
   $saved=$this->put('df_photo_render_review_'.$hash,$review);if($saved instanceof WP_Error)return $saved;
   if(!Logger::write('photo_render_reviewed',['review_sha256'=>$review['sha256']],'photo_input',(string)$r['snapshot']['order_id']))return self::error('audit_uncertain');return $approved;
  });
 }
 public function buyerPreview(string $session,string $version):array|WP_Error {
  $s=$this->status($session);if($s instanceof WP_Error||($s['state']??null)!=='APPROVED'||($s['editor_version']??null)!==$version)return self::error('current_review_required');$r=$this->current($session,$s['sha256'],$s['revision'],true);if($r instanceof WP_Error)return $r;
  $binding=$this->load('df_photo_render_'.$r['sha256']);if($binding instanceof WP_Error||$binding===null)return self::error('render_required');global $wpdb;$wpdb->last_error='';$render=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_render_evidence().' WHERE id=%d',$binding['render_id']),ARRAY_A);
  if($wpdb->last_error!==''||!is_array($render)||$render['review_status']!=='APPROVED'||(int)$render['reviewed_by']===$r['snapshot']['customer_user_id']||ShopAccess::authorize('personalized_pod','manage_digiforge_pod',(int)$render['reviewed_by'])!==true)return self::error('render_review_required');
  $review=$this->load('df_photo_render_review_'.$r['sha256']);if($review instanceof WP_Error||$review===null||$review['render_id']!==$binding['render_id']||$review['reviewer']!==(int)$render['reviewed_by']||$review['reviewed_at']!==$render['reviewed_at']||!$this->auditProof('photo_render_reviewed','review_sha256',$review['sha256'],$r['snapshot']['order_id']))return self::error('render_review_required');
  $artifact=(new DeterministicRenderWorkflow())->customerPreview($binding['render_id'],$binding['panel'],$session);if($artifact instanceof WP_Error)return $artifact;
  return $artifact+['state'=>'AVAILABLE','label'=>'Reviewed local artwork preview — not supplier certification','variant_key'=>(string)$r['snapshot']['provider_mapping_id'],'artifact_sha256'=>$artifact['sha256'],'context_sha256'=>hash('sha256',Validator::canonicalJson([$r['sha256'],$version,$binding,$artifact['sha256']])),'customer_approved'=>false,'selection_sha256'=>$r['sha256'],'digital_only'=>true,'supplier_certified'=>false];
 }
 public function current(string $session,string $hash,int $revision,bool $approved=false):array|WP_Error {
  $a=(new CustomerEditorRepository())->access($session,self::operator());if($a instanceof WP_Error)return $a;$r=$this->head($a['line_item_id']);if($r instanceof WP_Error)return $r;
  if($r===null||$r['sha256']!==$hash||$r['revision']!==$revision||$r['snapshot']['session_key']!==$session)return self::error('stale_selection');return $this->readSelection($r,$approved);
 }
 private function readSelection(array $r,bool $approved):array|WP_Error {
  $s=(new CustomerEditorRepository())->photoSnapshot($r['snapshot']['session_key'],$r['snapshot']['editor_version'],self::operator());if($s instanceof WP_Error)return $s;if(Validator::canonicalJson($s)!==Validator::canonicalJson($r['snapshot']))return self::error('source_changed');
  global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::personalization_submissions().' WHERE id=%d',$r['submission_id']),ARRAY_A);if($wpdb->last_error!==''||!is_array($row)||(int)$row['order_line_item_id']!==$s['line_item_id']||(int)$row['personalization_schema_id']!==$s['schema_id']||$row['payload_hash']!==$s['payload_sha256']||$row['canonical_payload']!==Validator::canonicalJson($s['payload']))return self::error('canonical_input_changed');
  $review=$this->load('df_photo_review_'.$r['sha256']);if($review instanceof WP_Error)return $review;$state=$row['review_status'];if($review!==null&&($review['selection_sha256']!==$r['sha256']||$review['canonical_review_status']!==$state||(int)$row['reviewed_by']!==$review['canonical_reviewed_by']||$row['reviewed_at']!==$review['canonical_reviewed_at']))return self::error('review_changed');
  if($review!==null&&!$this->auditProof('photo_input_reviewed','review_sha256',$review['sha256'],$s['order_id']))return self::error('review_audit_missing');
  if($approved&&($state!=='APPROVED'||$review===null||$review['decision']!=='APPROVED'||!user_can($review['reviewed_by'],'manage_digiforge_orders')||ShopAccess::authorize('personalized_pod','manage_digiforge_orders',$review['reviewed_by'])!==true||ShopAccess::authorize('personalized_pod','manage_digiforge_pod',$review['reviewed_by'])!==true))return self::error('approved_selection_required');return array_replace($r,['review_state'=>$review===null?'UNREVIEWED':$review['decision']]);
 }
 private function auditProof(string $event,string $field,string $sha,int $order):bool {
  if(!in_array($field,['selection_sha256','review_sha256'],true))return false;global $wpdb;$wpdb->last_error='';$n=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::audit_log()." WHERE event_type=%s AND object_type='photo_input' AND object_id=%s AND JSON_VALID(context) AND BINARY JSON_UNQUOTE(JSON_EXTRACT(context,'$.".$field."'))=BINARY %s",$event,(string)$order,$sha));return $wpdb->last_error===''&&is_numeric($n)&&(int)$n>0;
 }
 private function head(int $line):array|WP_Error|null {$h=$this->load('df_photo_head_'.$line);if($h instanceof WP_Error||$h===null)return $h;if(!is_string($h['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$h['sha256']))return self::error('head_corrupt');$r=$this->load('df_photo_selection_'.$h['sha256']);if($r instanceof WP_Error)return $r;return $r!==null&&self::sealed($r)&&$r['sha256']===$h['sha256']&&($r['snapshot']['line_item_id']??null)===$line&&$this->auditProof('photo_input_selected','selection_sha256',$r['sha256'],$r['snapshot']['order_id'])?$r:self::error('selection_corrupt');}
 private function panel(array $render):string {$t=(new RenderArtifactVerifier())->certifiedTemplate($render);return $t instanceof WP_Error?'':(count($t['print_areas'])===1?$t['print_areas'][0]['position']:'');}
 private function load(string $name):array|WP_Error|null {global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s',$name));if($wpdb->last_error!=='')return self::error('read_unavailable');if($raw===null)return null;if(!is_string($raw)||strlen($raw)>65536)return self::error('record_invalid');$r=json_decode($raw,true);if(!is_array($r))return self::error('record_invalid');if(str_starts_with($name,'df_photo_head_'))return $r;return self::sealed($r)?$r:self::error('record_corrupt');}
 private function put(string $name,array $r):array|WP_Error {global $wpdb;$old=$this->load($name);if($old instanceof WP_Error)return $old;$raw=Validator::canonicalJson($r);if(strlen($raw)>65536)return self::error('record_limit');if($old!==null)return Validator::canonicalJson($old)===$raw?$old:self::error('immutable_conflict');$wpdb->last_error='';if($wpdb->insert($wpdb->options,['option_name'=>$name,'option_value'=>$raw,'autoload'=>'no'])!==1||$wpdb->last_error!==''||Validator::canonicalJson((array)$this->load($name))!==$raw)return self::error('write_uncertain');return $r;}
 private function locked(int $line,callable $fn):array|WP_Error {global $wpdb;$name='df-photo-line-'.$line;$got=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$name));if((string)$got!=='1')return self::error('busy');$active=false;try{$tables=[$wpdb->options,Tables::audit_log(),Tables::personalization_submissions(),Tables::pod_render_evidence()];$engines=$wpdb->get_col($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s,%s)',...$tables));if(count($engines)!==4||array_filter($engines,fn($e)=>strtoupper($e)!=='INNODB')||$wpdb->query('START TRANSACTION')===false)return self::error('transaction_required');$active=true;try{$r=$fn();}catch(\Throwable){$r=self::error('operation_failed');}if($r instanceof WP_Error){$wpdb->query('ROLLBACK');}elseif($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');$r=self::error('commit_unknown');}$active=false;return $r;}finally{if($active)$wpdb->query('ROLLBACK');$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));}}
 private static function operator():bool{return get_current_user_id()>0&&current_user_can('manage_digiforge_pod')&&ShopAccess::authorize('personalized_pod','manage_digiforge_pod')===true;}
 private static function seal(array $r):array{$r['sha256']=hash('sha256',Validator::canonicalJson($r));return $r;}
 private static function sealed(array $r):bool{$sha=$r['sha256']??null;unset($r['sha256']);return is_string($sha)&&hash_equals($sha,hash('sha256',Validator::canonicalJson($r)));}
 private static function error(string $reason,int $status=409):WP_Error{return new WP_Error('photo_selection_'.$reason,'Current owned photo input, exact revision and independent review required.',['status'=>$status,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
