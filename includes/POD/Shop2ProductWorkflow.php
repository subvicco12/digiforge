<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Research\{CommercialResearchRepository,DesignFactoryContract};
use DigiForge\Security\{ShopAccess,Logger};
use DigiForge\PortalAccess\{Store,Session};
use DigiForge\Listings\Validator;
use WP_Error;
/** Local supplier-to-design linkage; never supplier, publication or order authority. */
final class Shop2ProductWorkflow {
 public static function access(bool $listing=false):bool {
  if(get_current_user_id()<1)return false;
  $s=Store::read(get_current_user_id());if(is_wp_error($s)||($s!==null&&(!$s['enabled']||!Session::valid($s)||$s['role']==='Viewer')))return false;
  $caps=['manage_digiforge','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_products'];if($listing)$caps[]='manage_digiforge_listings';
  foreach($caps as $cap)if(!current_user_can($cap)||ShopAccess::authorize('personalized_pod',$cap)!==true)return false;return true;
 }
 public function start(string $snapshot,string $concept,string $family,int $blueprint,int $provider,int $variant):array|WP_Error {
  if(!self::access())return self::error('Current Shop 2 product, POD and research assignments required.',403);
  if(!in_array($family,['mug','poster'],true))return self::error('Only the existing mug and poster technical profiles are supported.');
  $packet=(new ProductIntelligenceRepository())->read($snapshot);if(is_wp_error($packet))return $packet;
  $row=null;foreach($packet['sections']['concepts']['rows'] as $c)if($c['V2 ID']===$concept)$row=$c;if($row===null)return self::error('Exact original Master 500 concept required.');
  try{$review=PrintifySupplierWorkflow::review($packet,$blueprint,$provider,$variant);}catch(\InvalidArgumentException $e){return self::error($e->getMessage());}
  if(in_array('VARIANT_OBSERVATIONS_REQUIRE_RECONCILIATION',$review['gaps'],true))return self::error('Resolve conflicting selected variant observations first.');
  $research=['shop_key'=>'personalized_pod','title'=>$concept.' '.$family.' supplier review','product_family'=>$family,'sources'=>[['id'=>'supplier_catalog','kind'=>'supplier_catalog','url'=>add_query_arg(['df_view'=>'pod_personalized','df_shop'=>'personalized_pod','df_pi_snapshot'=>$snapshot],set_url_scheme(home_url('/'),'https')),'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',strtotime($review['evidence']['variants'][0]['source_modified_at'])),'excerpt'=>'Retained supplier selection only; demand, native behavior, physical geometry and shipping are unverified.','provenance'=>'Snapshot SHA-256 '.$snapshot.'; exact blueprint '.$blueprint.', provider '.$provider.', variant '.$variant.'. No source is promoted to approval.']]];
  $root=(new CommercialResearchRepository())->create($research);if(is_wp_error($root))return $root;
  $record=['schema'=>'shop2-supplier-workflow-v1','shop_key'=>'personalized_pod','snapshot_id'=>$snapshot,'concept_id'=>$concept,'source_dg_id'=>$row['Source DG ID']??null,'physical_product'=>$row['Physical Product'],'family'=>$family,'blueprint_id'=>$blueprint,'provider_id'=>$provider,'variant_id'=>$variant,'research_root'=>$root['hash'],'created_by'=>get_current_user_id(),'created_at'=>gmdate('c'),'external_execution_authorized'=>false];
  $raw=\DigiForge\Listings\Validator::canonicalJson($record);$hash=hash('sha256',$raw);
  if(!Logger::write('shop2_supplier_workflow_create_attempt',['workflow_sha256'=>$hash,'snapshot_sha256'=>$snapshot,'external_execution_authorized'=>false],'shop2_product_workflow',$hash))return self::error('Workflow audit unavailable.');
  // Exact repeated submission may resume only after content-addressed readback.
  add_option('digiforge_shop2_workflow_'.$hash,$raw,'',false);
  $saved=$this->read($hash);return is_wp_error($saved)?$saved:['hash'=>$root['hash'],'supplier_workflow_hash'=>$hash];
 }
 public function read(string $hash):array|WP_Error {
  if(!self::access())return self::error('Shop 2 workflow access denied.',403);
  if(!preg_match('/^[a-f0-9]{64}$/D',$hash))return self::error('Exact supplier workflow hash required.');
  global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s','digiforge_shop2_workflow_'.$hash));if(class_exists(\DigiForge\Connector\Printify\NativeProtectedOptionDependencies::class))\DigiForge\Connector\Printify\NativeProtectedOptionDependencies::observeOption('digiforge_shop2_workflow_'.$hash,$raw);
  if($wpdb->last_error!==''||!is_string($raw)||strlen($raw)>8192||!hash_equals($hash,hash('sha256',$raw)))return self::error('Supplier linkage missing, corrupt or unavailable.');
  $r=json_decode($raw,true);if(!is_array($r)||($r['schema']??null)!=='shop2-supplier-workflow-v1'||($r['shop_key']??null)!=='personalized_pod'||($r['external_execution_authorized']??null)!==false)return self::error('Invalid supplier linkage.');
  $packet=(new ProductIntelligenceRepository())->read($r['snapshot_id']);if(is_wp_error($packet))return $packet;
  try{$review=PrintifySupplierWorkflow::review($packet,$r['blueprint_id'],$r['provider_id'],$r['variant_id']);}catch(\Throwable){return self::error('Selected supplier evidence unavailable.');}
  return ['hash'=>$hash,'record'=>$r,'review'=>$review];
 }
 public function context(array $link,string $research):true|WP_Error {
  $history=(new CommercialResearchRepository())->history('personalized_pod',$research);if(is_wp_error($history))return $history;
  return in_array($link['record']['research_root'],array_column($history,'hash'),true)?true:self::error('Research belongs to another supplier workflow.');
 }
 /** Validate pixels only. No derived DPI, physical dimensions, safe zone or certification. */
 public function geometry(array $link,int $width,int $height):true|WP_Error {
  $areas=$link['review']['evidence']['print_areas'];if(count($areas)!==1)return self::error('Exactly one unambiguous catalog print area is required for these mug/poster profiles.');
  $f=$areas[0]['facts'];if(($f['Width PX']??null)!==(string)$width||($f['Height PX']??null)!==(string)$height)return self::error('Artwork technical canvas differs from selected supplier pixel dimensions.');return true;
 }
 public function package(array $link,array $e):true|WP_Error {
  $t=$e['template']??null;$r=$link['record'];if(!is_array($t)||($t['supplier']??null)!=='printify'||($t['provider_blueprint_id']??null)!==$r['blueprint_id']||($t['provider_id']??null)!==$r['provider_id']||($t['variant_ids']??null)!==[$r['variant_id']]||($t['template_status']??null)!=='DRAFT')return self::error('Exact selected supplier DRAFT template required; catalog evidence cannot certify production.');
  $a=$t['print_areas']??[];if(!is_array($a)||count($a)!==1||!is_int($a[0]['width_px']??null)||!is_int($a[0]['height_px']??null))return self::error('Exact bounded print-area dimensions required.');
  $g=$this->geometry($link,$a[0]['width_px'],$a[0]['height_px']);if(is_wp_error($g))return $g;
  $facts=$link['review']['evidence']['print_areas'][0]['facts'];
  if(($a[0]['position']??null)!==($facts['Print Area']??null)||!is_string($a[0]['decoration_method']??null)||$a[0]['decoration_method']==='' )return self::error('Exact selected print placement and explicit decoration declaration required.');
  if(is_string($facts['Decoration']??null)&&$facts['Decoration']!==''&&$a[0]['decoration_method']!==$facts['Decoration'])return self::error('Decoration conflicts with the selected catalog observation.');
  // Declaration is retained as unverified when the catalog omits decoration.
  return true;
 }
 public function template(array $link):?array {
  $areas=$link['review']['evidence']['print_areas'];if(count($areas)!==1)return null;$f=$areas[0]['facts'];
  foreach(['Width PX','Height PX'] as $k)if(!is_string($f[$k]??null)||!preg_match('/^[1-9][0-9]{0,6}$/D',$f[$k]))return null;
  if(!is_string($f['Print Area']??null)||!is_string($f['Decoration']??null)||$f['Decoration']==='')return null;
  $r=$link['record'];return ['template_id'=>'shop2-'.substr($link['hash'],0,16),'template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>$r['blueprint_id'],'provider_id'=>$r['provider_id'],'variant_ids'=>[$r['variant_id']],'personalization_pipeline'=>'PRINTIFY_NATIVE','personalization_engine'=>'MANUAL_DECLARATION_UNVERIFIED','template_status'=>'DRAFT','print_areas'=>[['position'=>$f['Print Area'],'decoration_method'=>$f['Decoration'],'width_px'=>(int)$f['Width PX'],'height_px'=>(int)$f['Height PX']]]];
 }
 /** Resolve current protected approval and supplier context before producing a transient local sample. */
 public function proof(array $link,string $design,array $samples):array|WP_Error {
  if(!self::access())return self::error('Current Shop 2 artwork assignment required.',403);
  if(!is_string($link['hash']??null))return self::error('Exact supplier workflow required.');
  $link=$this->read($link['hash']);if(is_wp_error($link))return $link;
  $handoff=(new \DigiForge\Research\DesignFactoryRepository())->handoff('personalized_pod',$design);if(is_wp_error($handoff))return $handoff;
  $valid=$this->context($link,$handoff['design_metadata']['commercial_hash']);if(is_wp_error($valid))return $valid;
  $spec=$handoff['design_metadata']['specification'];
  if(($spec['geometry']['family']??null)!==$link['record']['family'])return self::error('Proof product family differs from selected supplier.');
  $valid=$this->geometry($link,$spec['geometry']['width_px'],$spec['geometry']['height_px']);if(is_wp_error($valid))return $valid;
  try{$proof=LocalPersonalizationProof::render($spec,$handoff['artwork']['record']['input']['artwork']['svg'],$samples);}catch(\Throwable){return self::error('Exact bounded text samples required for every reserved region; overflow and image proofs need manual review.');}
  unset($proof['receipt']['sha256']);$proof['receipt']['supplier_workflow_hash']=$link['hash'];$proof['receipt']['design_factory_hash']=$design;
  $proof['receipt']['sha256']=hash('sha256',Validator::canonicalJson($proof['receipt']));return $proof;
 }
 public function media(array $link,string $design,array $media):true|WP_Error {
  if(!array_is_list($media)||count($media)>10)return self::error('At most ten exact imported mockup references required.');
  foreach($media as $m){if(!is_array($m)||!is_string($m['reference']??null)||!preg_match('#^shop2-mockups/([a-f0-9]{64})\.png$#D',$m['reference'],$match))return self::error('Use byte-verified local mockup imports.');
   $r=(new Shop2MockupRepository())->read($link,$design,$match[1]);if(is_wp_error($r))return $r;if(($m['sha256']??null)!==$r['image']['sha256']||($m['design_sha256']??null)!==$r['design_sha256'])return self::error('Mockup reference and image/artwork hashes differ.');
  }return true;
 }
 public function export(array $link,string $package):string|WP_Error {
  if(!self::access(true))return self::error('Shop 2 listings assignment required.',403);
  $repo=new ProductPackageRepository();$v=$repo->read('personalized_pod',$package);if(is_wp_error($v))return $v;
  $c=$this->context($link,$v['record']['commercial_design_metadata']['commercial_hash']??'');if(is_wp_error($c))return $c;
  $valid=$this->package($link,$v['record']['evidence']);if(is_wp_error($valid))return $valid;
  $valid=$this->media($link,$v['record']['commercial_design_metadata']['design_hash']??'',$v['record']['evidence']['media']??[]);if(is_wp_error($valid))return $valid;
  $raw=$repo->export('personalized_pod',$package);if(is_wp_error($raw))return $raw;$out=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
  $out['remaining_gates'][]='DECLARED_DECORATION_REQUIRES_PROVIDER_REVIEW';
  $out['remaining_gates'][]='SUPPLIER_MOCKUP_AUTHENTICITY_UNVERIFIED';
  $out['supplier_workflow_hash']=$link['hash'];$out['supplier_selection']=$link['record'];$out['supplier_selection']['catalog_evidence']=$link['review'];
  $out['etsy_draft']=['state'=>'UNSUBMITTED_LOCAL_WORKSHEET','fields'=>array_merge($out['package']['listing'],['price'=>$out['package']['economics']['assumptions']['sale_price'],'currency'=>$out['package']['economics']['currency']]),'personalization'=>$out['package']['native_personalization'],'media'=>$out['package']['media'],'publishing_authorized'=>false,'api_payload_ready'=>false,'manual_steps'=>['Review category, attributes, price, shipping and processing profiles.','Configure supported native buyer questions in the provider and Etsy dashboards; dashboard setup is not an API call.','Import reviewed mockup files and verify exact artwork parity.','Any provider transfer or Etsy draft creation requires separate authorization; do not publish.']];
  $out['printify_draft']=['state'=>'LOCAL_SPECIFICATION_ONLY','blueprint_id'=>$link['record']['blueprint_id'],'print_provider_id'=>$link['record']['provider_id'],'variant_ids'=>[$link['record']['variant_id']],'api_payload_ready'=>false,'missing'=>['VERIFIED_SHOP_BINDING','UPLOADED_PRINTIFY_IMAGE_ID','VERIFIED_VARIANT_PRICE_AND_PRINT_AREA_PAYLOAD'],'historical_fixture_product_id_is_not_new_product_identity'=>true];
  $encoded=\DigiForge\Listings\Validator::canonicalJson($out);return strlen($encoded)<=196608?$encoded:self::error('Bound export exceeds 192 KiB.');
 }
 /** Exact private portal reference, not an outbound URL or an authentication grant. */
 public static function snapshotReference(string $url):bool {
  $p=parse_url($url);$base=parse_url(set_url_scheme(home_url('/'),'https'));
  return is_array($p)&&is_array($base)&&($p['scheme']??null)==='https'&&($p['host']??null)===($base['host']??null)&&($p['port']??null)===($base['port']??null)&&($p['path']??'/')===($base['path']??'/')&&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['fragment'])&&preg_match('/^df_view=pod_personalized&df_shop=personalized_pod&df_pi_snapshot=[a-f0-9]{64}$/D',$p['query']??'')===1;
 }
 public static function error(string $message,int $status=409):WP_Error{return new WP_Error('shop2_product_workflow_denied',$message,['status'=>$status,'external_execution_authorized'=>false,'release_hold'=>true]);}
}
