<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Database\Tables;
use DigiForge\Portal\ReviewedAcceptanceContract;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** Offline preparation only; no registration, persistence, delivery or certification path. */
final class FC01ProductionArtifact
{
 public function prepare(array $input):array|WP_Error {
  try {
   if(wp_get_environment_type()!=='local'||get_current_user_id()<1||($input['shop_key']??null)!=='personalized_pod')throw new \InvalidArgumentException();
   $access=ShopAccess::authorize('personalized_pod','manage_digiforge_pod');if(is_wp_error($access))return $access;
   $keys=['shop_key','template_key','template_version','template_sha256','variant_id','position','decoration_method','geometry_review_scope','personalization'];$actual=array_keys($input);sort($actual);sort($keys);if($actual!==$keys)throw new \InvalidArgumentException();
   foreach(['template_key','position','decoration_method','geometry_review_scope']as $key)if(!is_string($input[$key])||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$input[$key])!==1)throw new \InvalidArgumentException();
   foreach(['template_version','variant_id']as $key)if(!is_int($input[$key])||$input[$key]<1)throw new \InvalidArgumentException();
   if(!is_string($input['template_sha256'])||preg_match('/^[a-f0-9]{64}$/D',$input['template_sha256'])!==1||!is_array($input['personalization'])||array_diff(array_keys($input['personalization']),['family_name','established'])!==[])throw new \InvalidArgumentException();
   $identity=FC01TextOnlyConcept::identity();$concept=FC01TextOnlyConcept::render(array_intersect_key($identity,array_flip(['shop_key','family_id','version','design_variant','spec_sha256']))+$input['personalization']);if(is_wp_error($concept))return $concept;
   global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_production_templates().' WHERE template_id=%s AND template_version=%d',$input['template_key'],$input['template_version']),ARRAY_A);
   if($wpdb->last_error!==''||!is_array($row)||!in_array($row['template_status'],['GEOMETRY_LOCKED','VALIDATED'],true))throw new \InvalidArgumentException();
   $row['variant_ids']=json_decode($row['variant_ids'],true,32,JSON_THROW_ON_ERROR);$row['print_areas']=json_decode($row['print_areas'],true,32,JSON_THROW_ON_ERROR);$template=ProductionTemplateContract::normalize($row);
   if($template['supplier']!=='printify'||$template['personalization_pipeline']!=='DIGIFORGE_RENDER'||!hash_equals($template['fingerprint'],$input['template_sha256'])||!hash_equals($template['fingerprint'],$row['fingerprint'])||!in_array($input['variant_id'],$template['variant_ids'],true))throw new \InvalidArgumentException();
   $owner=(new TemplateCycleRepository())->assertOwner('personalized_pod',$template['fingerprint']);if(is_wp_error($owner))return $owner;
   $current=(new ProductionTemplateRepository())->assertCurrentCatalogSnapshot($template);if(is_wp_error($current))return $current;
   $areas=array_values(array_filter($template['print_areas'],static fn(array $a):bool=>$a['position']===$input['position']&&$a['decoration_method']===$input['decoration_method']));if(count($areas)!==1)throw new \InvalidArgumentException();$area=$areas[0];
   // Raster PNG cannot implement embroidery, AOP/UV warps or unresolved Choice routing.
   if(!in_array($area['decoration_method'],['dtg','dtf','sublimation'],true)||$area['width_px']>10000||$area['height_px']>10000||$area['width_px']*$area['height_px']>25000000)throw new \InvalidArgumentException();
   $qa=ArtworkGeometryQa::policy($area);if($qa['state']!=='VERIFIED_LOCAL_GEOMETRY'||$qa['bleed_state']!=='VERIFIED_LOCAL_BLEED_GEOMETRY')throw new \InvalidArgumentException();
   $review=ReviewedAcceptanceContract::current('personalized_pod','BP-seam-panel-render',$input['geometry_review_scope']);if(is_wp_error($review))return $review;
   if($review['payload']['version']!==2)throw new \InvalidArgumentException();$d=$review['payload']['definition'];$o=$d['observations'];
   $environment=$area['_catalog_bindings'][0]['environment'];if($d['environment']!==$environment||strtotime($d['period_start'])>time()||strtotime($d['period_end'])<=time()||$o['template_sha256']!==$template['fingerprint'])throw new \InvalidArgumentException();
   foreach($area['_catalog_bindings'] as $binding){$observed=strtotime($binding['observed_at']);if($observed===false||$observed>time()||$observed<strtotime($d['period_start'])||$observed>=strtotime($d['period_end']))throw new \InvalidArgumentException();$refs=array_column($d['source_records'],'sha256');if(!in_array($binding['response_sha256'],$refs,true))throw new \InvalidArgumentException();}
   $evaluated=ReviewedAcceptanceContract::evaluate('personalized_pod','BP-seam-panel-render',$input['geometry_review_scope'],$environment);if(is_wp_error($evaluated)||$evaluated['state']!=='MATCHED_REVIEWED_DECLARATION')throw new \InvalidArgumentException();
   $panels=array_values(array_filter($o['panels'],static fn(array $p):bool=>$p['id']===$area['position']));if(count($panels)!==1||self::json($panels[0]['geometry'])!==self::json($area))throw new \InvalidArgumentException();
   // This binds reviewed declarations, not proof of supplier truth or a production grant.
   $packet=['schema'=>'fc01-production-artifact-request-v1','shop_key'=>'personalized_pod','environment'=>$environment,'template'=>['id'=>$template['template_id'],'version'=>$template['template_version'],'sha256'=>$template['fingerprint'],'provider'=>'printify','blueprint_id'=>$template['provider_blueprint_id'],'provider_id'=>$template['provider_id'],'variant_id'=>$input['variant_id']],'area'=>$area,'geometry_qa'=>$qa,'review'=>['scope'=>$input['geometry_review_scope'],'revision'=>$review['revision'],'sha256'=>$review['proposal_hash'],'effective_at'=>$review['effective_at'],'expires_at'=>$review['expires_at']],'identity'=>$identity,'source_sha256'=>['request_builder'=>hash_file('sha256',__FILE__),'compiler'=>hash_file('sha256',__DIR__.'/offline/fc01_artifact.py'),'font_provenance'=>hash_file('sha256',dirname(__DIR__,2).'/assets/pod-designs/fc01-v2-text-only/font/provenance.json')],'personalization'=>$input['personalization'],'input_sha256'=>$concept['input_sha256'],'text_runs'=>$concept['text_runs'],'regions'=>$concept['regions'],'export_policy'=>'TRANSPARENT_TEXT_UNIFORM_CONCEPT_SCALE_NO_AUTO_FIT','acceptance_state'=>'UNVERIFIED','supplier_certified'=>false,'rights_certified'=>false,'production_authorized'=>false,'external_execution_authorized'=>false];
   return $packet+['request_sha256'=>hash('sha256',self::json($packet))];
  }catch(\Throwable){return self::error();}
 }
 public function export(array $input):array|WP_Error {
  $packet=$this->prepare($input);if(is_wp_error($packet))return $packet;
  try {
   $result=self::worker(self::json($packet));
   // Long-running work must not cache approval, ownership or catalog admission.
   $current=$this->prepare($input);if(is_wp_error($current)||$current!==$packet)throw new \InvalidArgumentException();
   if(($result['request_sha256']??null)!==$packet['request_sha256']||($result['production_authorized']??null)!==false||!is_string($result['png_base64']??null))throw new \InvalidArgumentException();
   $png=base64_decode($result['png_base64'],true);if(!is_string($png)||strlen($png)>8388608||hash('sha256',$png)!==($result['png_sha256']??null))throw new \InvalidArgumentException();
   $size=getimagesizefromstring($png);if(!is_array($size)||$size[2]!==IMAGETYPE_PNG||$size[0]!==$packet['area']['width_px']||$size[1]!==$packet['area']['height_px'])throw new \InvalidArgumentException();
   if(($result['width_px']??null)!==$size[0]||($result['height_px']??null)!==$size[1]||($result['dpi']??null)!==$packet['area']['dpi']||($result['font_sha256']??null)!==$packet['identity']['font_sha256']||($result['license_sha256']??null)!==$packet['identity']['license_sha256']||($result['preview_final_parity']??null)!=='IDENTICAL_PNG_BYTES'||!is_array($result['ink_bounds']??null)||array_keys($result['ink_bounds'])!==array_keys($packet['text_runs']))throw new \InvalidArgumentException();
   foreach($result['ink_bounds']as $bounds)ArtworkGeometryQa::placement($packet['geometry_qa'],$bounds);
   return ['request'=>$packet,'receipt'=>array_diff_key($result,['png_base64'=>true]),'png'=>$png,'preview_png'=>$png,'final_png'=>$png,'review_status'=>'UNREVIEWED','proposed_review_locale'=>'en-US','locale_approved'=>false,'linguistic_review_state'=>'MISSING','artistic_review_state'=>'REVIEW_REQUIRED','acceptance_state'=>'UNVERIFIED','supplier_certified'=>false,'rights_certified'=>false,'production_authorized'=>false,'external_execution_authorized'=>false,'publishing_allowed'=>false];
  }catch(\Throwable){return self::error();}
 }
 private static function worker(string $input):array {
  $python=getenv('DIGIFORGE_CUTOUT_PYTHON');if(!is_string($python)||$python===''||$python[0]!=='/'||!is_file($python)||!is_executable($python)||!function_exists('proc_open'))throw new \InvalidArgumentException();
  $environment=['PATH'=>'/usr/bin:/bin','PYTHONNOUSERSITE'=>'1','OMP_NUM_THREADS'=>'1','OPENBLAS_NUM_THREADS'=>'1'];$path=getenv('DIGIFORGE_CUTOUT_PYTHONPATH');if(is_string($path)&&$path!=='')$environment['PYTHONPATH']=$path;
  $process=proc_open([$python,'-B','-s',__DIR__.'/offline/fc01_artifact.py'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,__DIR__,$environment,['bypass_shell'=>true]);if(!is_resource($process))throw new \InvalidArgumentException();
  $out='';$written=0;$stdin=true;$deadline=microtime(true)+30;
  try{foreach($pipes as $pipe)stream_set_blocking($pipe,false);while(true){if(microtime(true)>$deadline)throw new \InvalidArgumentException();if($stdin){$n=fwrite($pipes[0],substr($input,$written,65536));if($n===false)throw new \InvalidArgumentException();$written+=$n;if($written===strlen($input)){fclose($pipes[0]);$stdin=false;}}$out.=stream_get_contents($pipes[1],65536);stream_get_contents($pipes[2],65536);if(strlen($out)>12000000)throw new \InvalidArgumentException();$state=proc_get_status($process);if(!$state['running']){$out.=stream_get_contents($pipes[1],12000001-strlen($out));if($state['exitcode']!==0||strlen($out)>12000000)throw new \InvalidArgumentException();break;}usleep(1000);}return json_decode($out,true,32,JSON_THROW_ON_ERROR);
  }finally{$state=proc_get_status($process);if($state['running'])proc_terminate($process,9);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}
 }
 private static function json(array $value):string{return \DigiForge\Listings\Validator::canonicalJson($value);}
 private static function error():WP_Error{return new WP_Error('fc01_production_artifact_blocked','Exact current shop-scoped template, reviewed geometry, pinned font and bounded offline rendering are required. No supplier, rights or production certification is granted.',['status'=>409,'acceptance_state'=>'UNVERIFIED','external_execution_authorized'=>false]);}
}
