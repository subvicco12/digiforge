<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Listings\Validator;
use DigiForge\Research\DesignFactoryContract;
/** Offline native-text proof/preparation only. Never a provider request or production certification. */
final class PersonalizedProductPreparation {
 public static function profiles():array{return [
  'FL-03'=>['product_id'=>'6ae98d61eb5d7e24b80ca0a6','provider_name'=>'District Photo','family'=>'mug','width_px'=>2475,'height_px'=>1155,'dpi'=>300,'known_text_limits'=>[13,13,18]],
  'FL-04'=>['product_id'=>'6ae9913d9541969415088814','provider_name'=>"Today's Graphics",'family'=>'poster','width_px'=>2418,'height_px'=>3018,'dpi'=>300,'known_text_limits'=>null]
 ];}
 public static function prepare(array $input,array $supplier):array {
  Validator::rejectCredentials($input);
  if(array_diff(array_keys($input),['shop_key','fixture_id','template','artwork','values']))self::invalid('Unsupported preparation input.');
  if(($input['shop_key']??null)!=='personalized_pod'||($supplier['shop_key']??null)!=='personalized_pod')self::invalid('Exact Shop 2 context required.');
  $profile=self::profiles()[$input['fixture_id']??'']??null;if($profile===null)self::invalid('Known launch product required.');
  $t=$input['template']??null;$art=$input['artwork']??null;$values=$input['values']??null;
  if(!is_array($t)||!is_array($art)||!is_array($values)||($t['product_id']??null)!==$profile['product_id']||!is_int($t['version']??null)||$t['version']<1||$t['version']>10000)self::invalid('Versioned exact existing product/template required.');
  if(array_diff(array_keys($t),['product_id','version','source_sha256','layer_source_sha256','blueprint_id','provider_id','variant_id','width_px','height_px','dpi','fields','layers','qa_geometry'])||array_diff(array_keys($art),['png_base64','sha256','native_text_embedded']))self::invalid('Unsupported template or artwork metadata.');
  foreach(['source_sha256','layer_source_sha256']as $key)self::hash($t[$key]??null);
  foreach(['blueprint_id','provider_id','variant_id']as $key)if(!is_int($supplier[$key]??null)||$supplier[$key]<1||($t[$key]??null)!==$supplier[$key])self::invalid('Exact retained provider/variant binding required.');
  if(($supplier['evidence']['variants']??[])===[]||in_array('VARIANT_OBSERVATIONS_REQUIRE_RECONCILIATION',$supplier['gaps']??[],true))self::invalid('Unambiguous retained variant evidence required.');
  foreach(['width_px','height_px','dpi']as $key)if(($t[$key]??null)!==$profile[$key])self::invalid('Exact launch canvas and resolution required.');
  if(($art['native_text_embedded']??null)!==false||!is_string($art['png_base64']??null)||strlen($art['png_base64'])>2796204)self::invalid('Explicit fixed-only artwork required; native text must not be double printed.');
  $bytes=base64_decode($art['png_base64'],true);if(!is_string($bytes)||base64_encode($bytes)!==$art['png_base64'])self::invalid('Canonical bounded PNG required.');
  $image=DesignFactoryContract::mockup($bytes);self::hash($art['sha256']??null);if(!hash_equals($art['sha256'],hash('sha256',$bytes))||$image['width_px']!==$t['width_px']||$image['height_px']!==$t['height_px']||abs((float)($image['dpi']??0)-300)>.05)self::invalid('Exact fixed artwork bytes, pixels and 300 DPI required.');
  $fields=$t['fields']??null;$layers=$t['layers']??null;
  if(!is_array($fields)||!is_array($layers)||!array_is_list($fields)||!array_is_list($layers)||count($fields)!==3||count($layers)!==3)self::invalid('Exact three native fields and layers required.');
  PersonalizationPayloadContract::validate($fields,$values);
  foreach($values as $value)if(!is_string($value)||preg_match('/[\p{C}]/u',$value))self::invalid('Printable native text only; invisible controls are unsupported.');
  $limits=array_column($fields,'max_length');if(count($limits)!==3||($profile['known_text_limits']!==null&&$limits!==$profile['known_text_limits']))self::invalid('Exact qualified native limits required.');
  foreach($fields as $field)if(!in_array($field['type'],['text','date'],true)||!is_int($field['max_length']??null)||$field['max_length']<1||$field['max_length']>200||($field['required']??null)!==true)self::invalid('Explicit bounded required native text/date fields only; photos require existing manual image workflow.');
  $area=['width_px'=>$t['width_px'],'height_px'=>$t['height_px'],'dpi'=>300];if(isset($t['qa_geometry']))$area['qa_geometry']=$t['qa_geometry'];$policy=ArtworkGeometryQa::policy($area);
  $areas=$supplier['evidence']['print_areas']??[];$areaMapped=count($areas)===1;
  if($areaMapped){foreach(['Width PX'=>'width_px','Height PX'=>'height_px']as $column=>$key){$known=$areas[0]['facts'][$column]??null;if(is_string($known)&&preg_match('/^[1-9][0-9]{0,6}$/D',$known)){if((int)$known!==$t[$key])self::invalid('Selected provider pixels conflict with the exact template.');}else $areaMapped=false;}}
  $gaps=['PROVIDER_UNVERIFIED','QUALIFIED_R4_SOURCE_REVIEW_REQUIRED','ARTWORK_AND_FONT_RIGHTS_REVIEW_REQUIRED','NATIVE_PROVIDER_PREVIEW_PARITY_REQUIRED','TEXT_METRICS_AND_LEGIBILITY_REVIEW_REQUIRED','MOCKUP_RIGHTS_AND_AUTHENTICITY_REQUIRED','SHIPPING_PRICING_AND_REGIONAL_ACCEPTANCE_REQUIRED','HUMAN_APPROVAL_REQUIRED'];
  if(!$areaMapped)$gaps[]='EXACT_PRINT_AREA_MAPPING_REQUIRED';
  if($policy['state']!=='VERIFIED_LOCAL_GEOMETRY')$gaps[]='SAFE_AREA_GEOMETRY_MISSING';if($policy['bleed_state']==='MISSING_BLEED_GEOMETRY')$gaps[]='TRIM_AND_BLEED_EVIDENCE_MISSING';
  $seen=[];$ids=[];$bindings=[];$definitions=array_column($fields,null,'key');$svg='<svg xmlns="http://www.w3.org/2000/svg" width="'.$t['width_px'].'" height="'.$t['height_px'].'" viewBox="0 0 '.$t['width_px'].' '.$t['height_px'].'"><image width="'.$t['width_px'].'" height="'.$t['height_px'].'" href="data:image/png;base64,'.$art['png_base64'].'"/>';
  foreach($layers as $layer){if(!is_array($layer)||array_diff(array_keys($layer),['field','layer_id','x','y','width','height','font_size']))self::invalid('Exact supported native layer metadata required.');$key=$layer['field']??null;$id=$layer['layer_id']??null;if(!is_string($key)||!array_key_exists($key,$values)||isset($seen[$key])||!is_string($id)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D',$id)||isset($ids[$id]))self::invalid('Unique exact field-to-layer mapping required.');$seen[$key]=true;$ids[$id]=true;
   foreach(['x','y','width','height','font_size']as $k)if(!is_int($layer[$k]??null)||$layer[$k]<($k==='x'||$k==='y'?0:1)||$layer[$k]>10000)self::invalid('Explicit bounded native layer geometry required.');
   if($layer['x']+$layer['width']>$t['width_px']||$layer['y']+$layer['height']>$t['height_px']||$layer['font_size']>$layer['height'])self::invalid('Native layer escapes canvas.');
   ArtworkGeometryQa::placement($policy,$layer);
   $value=htmlspecialchars($values[$key],ENT_QUOTES|ENT_XML1,'UTF-8');$svg.='<text data-layer="'.$id.'" x="'.$layer['x'].'" y="'.($layer['y']+$layer['font_size']).'" font-size="'.$layer['font_size'].'">'.$value.'</text>';
   $bindings[]=['field'=>$key,'layer_id'=>$id,'value'=>$values[$key],'bounds'=>array_intersect_key($layer,array_flip(['x','y','width','height'])),'font_size'=>$layer['font_size'],'field_definition'=>$definitions[$key]];
  }
  if(array_diff(array_column($fields,'key'),array_keys($seen)))self::invalid('Every native field must bind once.');$svg.='</svg>';
  $templateHash=hash('sha256',Validator::canonicalJson($t));$inputHash=hash('sha256',Validator::canonicalJson($values));
  return ['schema'=>'personalized-preparation-v1','shop_key'=>'personalized_pod','fixture_id'=>$input['fixture_id'],'product_id'=>$profile['product_id'],'template_sha256'=>$templateHash,'template_version'=>$t['version'],'template_evidence'=>$t,'source_sha256'=>$t['source_sha256'],'layer_source_sha256'=>$t['layer_source_sha256'],'personalization_sha256'=>$inputHash,'artwork_sha256'=>$art['sha256'],'supplier'=>$supplier,'geometry'=>$policy,'canvas'=>array_intersect_key($t,array_flip(['width_px','height_px','dpi'])),'native_bindings'=>$bindings,'production'=>['mode'=>'NATIVE_TEXT','fixed_artwork_sha256'=>$art['sha256'],'native_text_rendered_into_production'=>false,'file_generation'=>'EXISTING_FIXED_ARTIFACT_PLUS_NATIVE_LAYER_INSTRUCTIONS','fixed_artifact'=>['mime'=>'image/png','contents_base64'=>$art['png_base64'],'sha256'=>$art['sha256'],'width_px'=>$t['width_px'],'height_px'=>$t['height_px'],'dpi'=>300]],'proof_svg'=>$svg,'proof_sha256'=>hash('sha256',$svg),'proof_kind'=>'LOCAL_PREVIEW_NOT_PRODUCTION_FILE','software_status'=>'SOFTWARE_IMPLEMENTED','provider_status'=>'PROVIDER_UNVERIFIED','state'=>'HUMAN_APPROVAL_REQUIRED','remaining_gates'=>array_values(array_unique($gaps)),'production_cost'=>null,'tax_amount'=>null,'shipping_rate'=>null,'external_execution_authorized'=>false,'provider_submission_authorized'=>false,'publishing_authorized'=>false];
 }
 private static function hash(mixed $h):void{if(!is_string($h)||!preg_match('/^[a-f0-9]{64}$/D',$h))self::invalid('Exact source digest required.');}
 private static function invalid(string $message):never{throw new \InvalidArgumentException($message);}
}
