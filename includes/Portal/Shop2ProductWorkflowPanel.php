<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\POD\{Shop2ProductWorkflow,ProductPackageRepository};
use DigiForge\Research\DesignFactoryContract;
use WP_Error;
/** A scoped local adapter over the existing artwork and package workflow. No supplier transport. */
final class Shop2ProductWorkflowPanel {
 public const ACTION='digiforge_shop2_product_workflow';
 private const OPS=['personalization_schema','personalization_sample','personalization_review','proof_preview','provider_prepare','provider_inspect','provider_authorize','provider_execute','provider_receipt','etsy_draft_prepare','revise_research','revise','mockup_import','inspect','concepts','select','design_approve_concept','design_prepare','design_import','design_review','design_inspect','design_revise','package_bundle','package_prepare','package_inspect','package_decide','package_export'];
 public function register():void{add_action('admin_post_'.self::ACTION,[$this,'submit']);}
 public function process(array $post):array|WP_Error {
  $op=$post['operation']??null;
  if(($post['shop_key']??null)!=='personalized_pod'||!is_string($op)||!Shop2ProductWorkflow::access(str_starts_with($op,'package_')))return Shop2ProductWorkflow::error('Current signed Shop 2 assignments and an enabled editing session are required.',403);
  if(strlen((string)wp_json_encode($post))>8500000)return Shop2ProductWorkflow::error('Bounded request required.');
  $repo=new Shop2ProductWorkflow();
  if($op==='supplier_start'){
   foreach(['snapshot_id','concept_id','product_family','blueprint_id','provider_id','variant_id'] as $key)if(!is_string($post[$key]??null))return Shop2ProductWorkflow::error('Exact typed supplier selection required.');
   foreach(['blueprint_id','provider_id','variant_id'] as $key)if(!preg_match('/^[1-9][0-9]{0,9}$/D',$post[$key])||(int)$post[$key]>2147483647)return Shop2ProductWorkflow::error('Positive bounded supplier IDs required.');
   return $repo->start($post['snapshot_id'],$post['concept_id'],$post['product_family'],(int)$post['blueprint_id'],(int)$post['provider_id'],(int)$post['variant_id']);
  }
  if(!in_array($op,self::OPS,true)||!is_string($post['supplier_workflow_hash']??null)||!is_string($post['workflow_hash']??null))return Shop2ProductWorkflow::error('Exact local operation and supplier/research versions required.');
  $link=$repo->read($post['supplier_workflow_hash']);if(is_wp_error($link))return $link;$valid=$repo->context($link,$post['workflow_hash']);if(is_wp_error($valid))return $valid;
  if(str_starts_with($op,'provider_')||$op==='etsy_draft_prepare')return (new Shop2ProviderProductPanel())->process($post,$link);
  if($op==='revise_research'){
   $root=(new \DigiForge\Research\CommercialResearchRepository())->read('personalized_pod',$link['record']['research_root']);if(is_wp_error($root))return $root;
   if(($post['product_family']??null)!==$link['record']['family']||!is_array($post['sources']??null))return Shop2ProductWorkflow::error('Preserve supplier-bound family and source evidence.');
   $found=[];foreach($post['sources'] as $source)if(is_array($source)&&($source['id']??null)==='supplier_catalog')$found[]=$source;
   if(count($found)!==1||\DigiForge\Listings\Validator::canonicalJson($found[0])!==\DigiForge\Listings\Validator::canonicalJson($root['record']['research']['sources'][0]))return Shop2ProductWorkflow::error('The original supplier_catalog source must remain unchanged.');
  }
  if($op==='proof_preview'){
   if(!is_string($post['factory_hash']??null)||!is_string($post['samples_json']??null)||strlen($post['samples_json'])>4096)return Shop2ProductWorkflow::error('Exact approved artwork and bounded sample object required.');
   try{$samples=json_decode($post['samples_json'],true,4,JSON_THROW_ON_ERROR);}catch(\Throwable){return Shop2ProductWorkflow::error('Valid sample text JSON required.');}
   if(!is_array($samples))return Shop2ProductWorkflow::error('Named text samples required.');
   $proof=$repo->proof($link,$post['factory_hash'],$samples);return is_wp_error($proof)?$proof:['proof'=>$proof];
  }
  if($op==='mockup_import'){
   foreach(['factory_hash','png_base64','note'] as $key)if(!is_string($post[$key]??null))return Shop2ProductWorkflow::error('Exact approved design, PNG and provenance note required.');
   $r=(new \DigiForge\POD\Shop2MockupRepository())->import($link,$post['factory_hash'],$post['png_base64'],$post['note']);return is_wp_error($r)?$r:['hash'=>$post['workflow_hash'],'supplier_workflow_hash'=>$link['hash'],'design_factory_hash'=>$post['factory_hash'],'mockup'=>$r];
  }
  if($op==='design_prepare'){
   $profile=$post['profile']??null;$spec=is_string($profile)?(DesignFactoryContract::PROFILES[$profile]??null):null;
   if($spec===null||$spec['family']!==$link['record']['family'])return Shop2ProductWorkflow::error('Choose the recorded mug/poster technical profile.');
   $valid=$repo->geometry($link,$spec['width_px'],$spec['height_px']);if(is_wp_error($valid))return $valid;
  }
  if($op==='package_prepare'){
   $raw=$post['evidence_json']??null;if(!is_string($raw)||strlen($raw)>65536)return Shop2ProductWorkflow::error('Bounded complete package evidence required.');
   try{$e=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){return Shop2ProductWorkflow::error('Valid package evidence required.');}
   if(!is_array($e))return Shop2ProductWorkflow::error('Package evidence object required.');$valid=$repo->package($link,$e);if(is_wp_error($valid))return $valid;
   if(!is_string($post['factory_hash']??null))return Shop2ProductWorkflow::error('Exact approved design required.');
   if(($e['media']??[])===[]){$media=(new \DigiForge\POD\Shop2MockupRepository())->media($link,$post['factory_hash']);if(is_wp_error($media))return $media;$e['media']=$media;}
   $valid=$repo->media($link,$post['factory_hash'],$e['media']??[]);if(is_wp_error($valid))return $valid;$bindingRequested=isset($post['personalization_hash'])||isset($post['business_integration_id'])||isset($post['business_mapping_id'])||isset($post['business_asset_spec_id'])||isset($e['shop2_binding']);
   if($bindingRequested){$binding=\DigiForge\POD\Shop2HeldPackageBinding::prepare($link,$post['workflow_hash'],$post['factory_hash'],['integration_id'=>$post['business_integration_id']??null,'provider_mapping_id'=>$post['business_mapping_id']??null,'asset_spec_id'=>$post['business_asset_spec_id']??null],$e,$post);if(is_wp_error($binding))return $binding;$e['shop2_binding']=$binding;}
   $post['evidence_json']=\DigiForge\Listings\Validator::canonicalJson($e);
  }elseif(str_starts_with($op,'package_')){
   $hash=$post['package_hash']??null;if(!is_string($hash))return Shop2ProductWorkflow::error('Exact package required.');
   $v=(new ProductPackageRepository())->read('personalized_pod',$hash);if(is_wp_error($v))return $v;
   $valid=$repo->package($link,$v['record']['evidence']);if(is_wp_error($valid))return $valid;
   $valid=$repo->media($link,$v['record']['commercial_design_metadata']['design_hash']??'',$v['record']['evidence']['media']??[]);if(is_wp_error($valid))return $valid;
  }
  if(in_array($op,['package_export','package_bundle'],true)){
   if(($post['confirm']??null)!=='1'||!is_string($post['factory_hash']??null))return Shop2ProductWorkflow::error('Exact approved held package and confirmation required.');
   if($op==='package_export'){$bound=\DigiForge\POD\Shop2HeldPackageBinding::export($link,$post['workflow_hash'],$post['factory_hash'],$post['package_hash'],$post);return is_wp_error($bound)?$bound:['hash'=>$post['workflow_hash'],'design_factory_hash'=>$post['factory_hash'],'package_hash'=>$post['package_hash'],'supplier_workflow_hash'=>$link['hash'],'package_export'=>$bound];}
   $before=\DigiForge\POD\Shop2HeldPackageBinding::inspect($link,$post['workflow_hash'],$post['factory_hash'],$post['package_hash'],$post);if(is_wp_error($before))return $before;
   $bytes=\DigiForge\POD\Shop2ReviewBundle::build($link,$post['package_hash']);if(is_wp_error($bytes))return $bytes;
   $after=\DigiForge\POD\Shop2HeldPackageBinding::inspect($link,$post['workflow_hash'],$post['factory_hash'],$post['package_hash'],$post);if(is_wp_error($after)||$before!==$after)return Shop2ProductWorkflow::error('Proof, business, package decision or current session changed during bundle assembly.');
   return ['hash'=>$post['workflow_hash'],'design_factory_hash'=>$post['factory_hash'],'package_hash'=>$post['package_hash'],'supplier_workflow_hash'=>$link['hash'],'package_bundle'=>$bytes];
  }
  if($op==='package_decide'&&isset($v['record']['evidence']['shop2_binding'])){$check=\DigiForge\POD\Shop2HeldPackageBinding::inspect($link,$post['workflow_hash'],$post['factory_hash'],$post['package_hash'],$post);if(is_wp_error($check))return $check;}
  $result=(new CommercialResearchPanel())->process($post);if(is_wp_error($result))return $result;
  $result['supplier_workflow_hash']=$link['hash'];return $result;
 }
 public function render(string $baseUrl):void {
  if(!Shop2ProductWorkflow::access()||(isset($_GET['df_shop'])&&$_GET['df_shop']!=='personalized_pod'))return;
  echo '<section class="df-panel" id="df-shop2-workflow"><h2>Shop 2 · Supplier → Artwork → Held Etsy package</h2><p>Offline mug/poster workflow. No product creation, image upload, supplier mutation or Etsy draft is submitted. Native layer creation remains manual where no API is documented; supported personalization and preview operations are connected below under separate authorization. Catalog pixels never certify physical templates.</p>';
  if(isset($_GET['df_workflow_error']))echo '<p role="alert">'.esc_html(is_string($_GET['df_workflow_error'])?$_GET['df_workflow_error']:'Operation blocked.').'</p>';
  $hash=$_GET['df_supplier_workflow']??null;
  if(!is_string($hash)||$hash===''){
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.self::ACTION.'"><input type="hidden" name="operation" value="supplier_start"><input type="hidden" name="shop_key" value="personalized_pod"><input type="hidden" name="return_url" value="'.esc_attr($baseUrl).'">';wp_nonce_field(self::ACTION,'_df_workflow_nonce');
   foreach(['snapshot_id'=>'Research snapshot SHA-256','concept_id'=>'Original concept ID (DG2-xxx)','blueprint_id'=>'Blueprint ID','provider_id'=>'Provider ID','variant_id'=>'Variant ID'] as $key=>$label){$q=['snapshot_id'=>'df_pi_snapshot','blueprint_id'=>'df_pi_blueprint','provider_id'=>'df_pi_provider','variant_id'=>'df_pi_variant'][$key]??'';$v=$q!==''&&is_string($_GET[$q]??null)?$_GET[$q]:'';echo '<p><label>'.esc_html($label).'<input name="'.esc_attr($key).'" value="'.esc_attr($v).'" maxlength="64" required></label></p>';}
   echo '<label>Declared product family<select name="product_family"><option value="mug">Mug · 2475 × 1155 technical profile</option><option value="poster">Poster · 2418 × 3018 technical profile</option></select></label><p>Selected supplier geometry must match before artwork preparation. Family is a review declaration, not supplier certification.</p><button class="df-button">Start supplier-bound workflow</button></form></section>';return;
  }
  $repo=new Shop2ProductWorkflow();$link=$repo->read($hash);if(is_wp_error($link)){echo '<p>'.esc_html($link->get_error_message()).'</p></section>';return;}
  $research=$_GET['df_research_version']??$link['record']['research_root'];if(!is_string($research)||is_wp_error($repo->context($link,$research))){echo '<p>Research does not belong to this supplier selection.</p></section>';return;}
  echo '<p>Selected '.esc_html($link['record']['concept_id'].' · blueprint '.$link['record']['blueprint_id'].' · provider '.$link['record']['provider_id'].' · variant '.$link['record']['variant_id']).'</p><h3>Native personalization and mockups</h3><p>Import original SVG through the existing design workflow. Reserve native text/photo layers; declare limits and provider field references in the package specification. Configure native field layouts in the provider editor where required. Use the connected options, personalization and preview-task APIs below where enabled, or import manual mockup files. Missing evidence remains a release gate. No simulated API or generated mockup is supplied.</p>';
  $saved=$_GET;$_GET['df_research_version']=$research;ob_start();try{(new CommercialResearchPanel())->render('personalized_pod',$repo->template($link),true,true);$html=ob_get_clean();}catch(\Throwable $e){ob_end_clean();throw $e;}finally{$_GET=$saved;}
  $hidden='<input type="hidden" name="supplier_workflow_hash" value="'.esc_attr($hash).'">'.wp_nonce_field(self::ACTION,'_df_workflow_nonce',false,false);
  $html=str_replace('name="action" value="digiforge_commercial_research"','name="action" value="'.self::ACTION.'"',$html);
  $html=str_replace('</form>',$hidden.'</form>',$html);$html=str_replace('df_view=research','df_view=pod_personalized',$html);
  // Preserve the supplier linkage on history links; no protected content is fetched here.
  $html=preg_replace_callback('/href="([^"]*)"/',static function($m)use($hash){$url=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');if(str_contains($url,'df_research_version='))return 'href="'.esc_url(add_query_arg('df_supplier_workflow',$hash,$url)).'"';return $m[0];},$html);
  $html=$this->bindingForms($html);
  echo $html;$this->proofForm($link,$research,$baseUrl);$this->bundleForm($link,$research,$baseUrl);$this->mockups($link,$research,$baseUrl);(new Shop2ProviderProductPanel())->render($link,$research,$baseUrl);echo '<p>For a package import, supply an exact selected-supplier DRAFT template with explicit placement, decoration and catalog-matching pixels. This workflow never assigns VALIDATED status. The existing historical FL-03/FL-04 product IDs are technical anchors, not a newly created Printify product.</p></section>';
 }
 /** View hints only. POST values are independently resolved through the protected binding service. */
 private function bindingForms(string $html):string {
  $proof=$_GET['df_personalization_version']??null;$hidden=is_string($proof)&&preg_match('/^[a-f0-9]{64}$/D',$proof)?'<input type="hidden" name="personalization_hash" value="'.esc_attr($proof).'">':'';
  $saved=[];$package=$_GET['df_package_version']??null;
  if(is_string($package)){$v=(new ProductPackageRepository())->read('personalized_pod',$package);if(is_array($v))$saved=$v['record']['evidence']['shop2_binding']['business']['selectors']??[];}
  return (string)preg_replace_callback('#<form\b.*?</form>#s',static function(array $m)use($hidden,$saved):string{
   $form=$m[0];if(!str_contains($form,'name="operation" value="package_'))return $form;
   $extra=$hidden;
   if(str_contains($form,'name="operation" value="package_prepare"')){
    $extra.='<fieldset><legend>Current approved core business binding</legend><p>A server-selected reviewed personalization proof is required. Enter existing approved core IDs; these selectors grant no authority. New bindings require a fresh exact package decision.</p>';
    foreach(['business_integration_id'=>['integration_id','df_business_integration','Reviewed Printify connection ID'],'business_mapping_id'=>['provider_mapping_id','df_business_mapping','Approved provider mapping ID'],'business_asset_spec_id'=>['asset_spec_id','df_business_asset_spec','Approved artwork asset-spec ID']]as $name=>[$key,$query,$label]){$v=$_GET[$query]??$saved[$key]??'';$value=is_scalar($v)&&preg_match('/^[1-9][0-9]{0,9}$/D',(string)$v)?(string)$v:'';$extra.='<label>'.esc_html($label).'<input type="number" name="'.esc_attr($name).'" value="'.esc_attr($value).'" min="1" max="2147483647" required></label>';}
    $extra.='</fieldset>';
   }
   return str_replace('</form>',$extra.'</form>',$form);
  },$html);
 }
 private function proofForm(array $link,string $research,string $baseUrl):void {
  $design=$_GET['df_factory_version']??null;if(!is_string($design))return;
  $h=(new \DigiForge\Research\DesignFactoryRepository())->handoff('personalized_pod',$design);if(is_wp_error($h))return;
  $samples=[];foreach($h['design_metadata']['specification']['layout']['native_regions'] as $r){if($r['kind']!=='text'){echo '<p>Local text proof unavailable for image regions. Image placement requires separate review.</p>';return;}$samples[$r['name']]='SAMPLE';}
  echo '<h3>Local personalization proof</h3><p>Enter synthetic sample text only. This download overlays reserved text regions using a generic font. Overflow is rejected; provider typography, safe zones and print parity still require review. Samples are not saved by DigiForge. RELEASE HOLD.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  foreach(['action'=>self::ACTION,'operation'=>'proof_preview','shop_key'=>'personalized_pod','supplier_workflow_hash'=>$link['hash'],'workflow_hash'=>$research,'factory_hash'=>$design,'return_url'=>$baseUrl] as $k=>$v)echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
  wp_nonce_field(self::ACTION,'_df_workflow_nonce');echo '<label>Named sample text JSON<textarea name="samples_json" maxlength="4096" required>'.esc_textarea((string)wp_json_encode($samples)).'</textarea></label><button class="df-button">Download local sample proof SVG</button></form>';
 }
 private function bundleForm(array $link,string $research,string $baseUrl):void {
  $package=$_GET['df_package_version']??null;$design=$_GET['df_factory_version']??null;if(!is_string($package)||!is_string($design)||!Shop2ProductWorkflow::access(true))return;
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';foreach(['action'=>self::ACTION,'operation'=>'package_bundle','shop_key'=>'personalized_pod','supplier_workflow_hash'=>$link['hash'],'workflow_hash'=>$research,'factory_hash'=>$design,'package_hash'=>$package,'return_url'=>$baseUrl] as $k=>$v)echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';wp_nonce_field(self::ACTION,'_df_workflow_nonce');echo '<label><input type="checkbox" name="confirm" value="1" required>I request a private local review bundle only.</label><button class="df-button">Download approved review ZIP: artwork, mockups and Etsy worksheet</button></form>';
 }
 private function mockups(array $link,string $research,string $baseUrl):void {
  $design=$_GET['df_factory_version']??null;if(!is_string($design))return;
  $repo=new \DigiForge\POD\Shop2MockupRepository();$media=$repo->media($link,$design);if(is_wp_error($media))return;
  echo '<h3>Import reviewed manual mockup images</h3><p>PNG only, up to 2 MiB and 4000 × 4000 pixels. Bytes, CRCs and artwork binding are checked; provider authenticity and visual parity still need human review.</p><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
  foreach(['action'=>self::ACTION,'operation'=>'mockup_import','shop_key'=>'personalized_pod','supplier_workflow_hash'=>$link['hash'],'workflow_hash'=>$research,'factory_hash'=>$design,'return_url'=>$baseUrl] as $k=>$v)echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
  wp_nonce_field(self::ACTION,'_df_workflow_nonce');echo '<label>Source and rights note<input name="note" maxlength="2000" required></label><label>PNG mockup<input type="file" name="mockup_png" accept="image/png,.png" required></label><button class="df-button">Import immutable mockup evidence</button></form><p>New package versions include these imported files when the media list is empty. Existing packages never change.</p>';
  foreach($media as $item){$id=basename($item['reference'],'.png');$r=$repo->read($link,$design,$id);if(is_wp_error($r))continue;echo '<details><summary>'.esc_html($item['reference']).'</summary><img alt="Manually imported mockup — supplier authenticity unverified" style="max-width:100%;height:auto" src="data:image/png;base64,'.esc_attr($r['base64']).'"><p>SHA-256 '.esc_html($r['image']['sha256']).' · '.esc_html($r['note']).'</p></details>';}
 }
 public function submit():never {
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST')wp_die('POST required.','',['response'=>405]);check_admin_referer(self::ACTION,'_df_workflow_nonce');
  $post=wp_unslash($_POST);
  if(($post['operation']??null)==='mockup_import'){$f=$_FILES['mockup_png']??[];if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name'])||filesize($f['tmp_name'])>2097152)wp_die('Bounded PNG upload required.','',['response'=>400]);$post['png_base64']=base64_encode((string)file_get_contents($f['tmp_name'],false,null,0,2097153));}
  $r=$this->process($post);
  if(is_array($r)&&is_string($r['proof']['svg']??null)){nocache_headers();header('Content-Type: image/svg+xml; charset=utf-8');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; style-src 'none'; sandbox");header('Content-Disposition: attachment; filename="digiforge-local-sample-proof.svg"');echo $r['proof']['svg'];exit;}
  if(is_array($r)&&is_string($r['provider_export']??null)){nocache_headers();header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="shop2-etsy-draft-plan.json"');echo $r['provider_export'];exit;}
  if(is_array($r)&&is_string($r['package_bundle']??null)){nocache_headers();header('Content-Type: application/zip');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="shop2-held-review-'.$r['package_hash'].'.zip"');echo $r['package_bundle'];exit;}
  if(is_array($r)&&is_string($r['package_export']??null)){nocache_headers();header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="shop2-held-etsy-package-'.$r['package_hash'].'.json"');echo $r['package_export'];exit;}
  $args=['df_view'=>'pod_personalized','df_shop'=>'personalized_pod'];if(is_wp_error($r)){$args['df_workflow_error']=$r->get_error_message();$r=$post;}
  foreach(['supplier_workflow_hash'=>'df_supplier_workflow','hash'=>'df_research_version','design_factory_hash'=>'df_factory_version','personalization_hash'=>'df_personalization_version','package_hash'=>'df_package_version','provider_plan_hash'=>'df_provider_plan','provider_authorization_hash'=>'df_provider_authorization','provider_receipt_hash'=>'df_provider_receipt'] as $key=>$q)if(is_string($r[$key]??null)&&preg_match('/^[a-f0-9]{64}$/D',$r[$key]))$args[$q]=$r[$key];
  if(!isset($args['df_research_version'])&&is_string($post['workflow_hash']??null)&&preg_match('/^[a-f0-9]{64}$/D',$post['workflow_hash']))$args['df_research_version']=$post['workflow_hash'];
  if(!isset($args['df_factory_version'])&&is_string($post['factory_hash']??null)&&preg_match('/^[a-f0-9]{64}$/D',$post['factory_hash']))$args['df_factory_version']=$post['factory_hash'];
  if(!isset($args['df_personalization_version'])&&is_string($post['personalization_hash']??null)&&preg_match('/^[a-f0-9]{64}$/D',$post['personalization_hash']))$args['df_personalization_version']=$post['personalization_hash'];
  foreach(['business_integration_id'=>'df_business_integration','business_mapping_id'=>'df_business_mapping','business_asset_spec_id'=>'df_business_asset_spec']as $key=>$q)if(is_string($post[$key]??null)&&preg_match('/^[1-9][0-9]{0,9}$/D',$post[$key])&&(int)$post[$key]<=2147483647)$args[$q]=$post[$key];
  $return=is_string($post['return_url']??null)?wp_validate_redirect($post['return_url'],home_url('/')):home_url('/');wp_safe_redirect(add_query_arg($args,$return).'#df-shop2-workflow');exit;
 }
}
