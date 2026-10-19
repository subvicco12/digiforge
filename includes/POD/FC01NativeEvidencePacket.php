<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\Security\ReviewedLocalEvidence;
use DigiForge\Security\ShopAccess;

/** Offline declaration/byte matching only. No storage, network, activation or certification. */
final class FC01NativeEvidencePacket
{
 public const SCHEMA='fc01-native-evidence-packet-v1';
 private const CHECKS=['N01','N02','N03','N04','N05','N06','N07','N08'];
 /** Use the existing independent review store; this helper creates no review or approval. */
 public static function reviewIdentity(array $packet):array {
  $sha=hash('sha256',self::json($packet));
  return ['key'=>'fc01-native:'.$sha,'payload'=>['schema'=>self::SCHEMA,'shop_key'=>'personalized_pod','review_scope'=>'FC01_NATIVE_EVIDENCE_COMPLETENESS','packet_sha256'=>$sha]];
 }
 /** Source IDs address caller-supplied bytes, never paths/URLs to be fetched. */
 public static function inspect(array $packet,array $bytes):array {
  $out=['schema'=>self::SCHEMA,'matched_section_count'=>0,'sections'=>array_fill_keys(self::CHECKS,['state'=>'UNKNOWN']),
   'acceptance_state'=>'UNVERIFIED','native_compatibility'=>'UNKNOWN','rights_certified'=>false,'linguistic_approved'=>false,
   'production_authorized'=>false,'external_execution_authorized'=>false,'publishing_allowed'=>false,'read_only'=>true];
  try{
   if(is_wp_error(ShopAccess::authorize('personalized_pod','manage_digiforge_connections')))return $out;
   self::require(self::keys($packet,['schema','shop_key','evidence_kind','concept_spec_sha256','connection','identity','sources','sections']));
   self::require($packet['schema']===self::SCHEMA&&$packet['shop_key']==='personalized_pod'&&in_array($packet['evidence_kind'],['SYNTHETIC_TEST_ONLY','OWNER_SUPPLIED'],true));
   self::require(strlen(self::json($packet))<=32768&&$packet['concept_spec_sha256']===FC01OfflineConcept::identity()['spec_sha256']);
   $context=$packet['connection'];self::require(is_array($context)&&($context['shop_key']??null)==='personalized_pod'&&($context['capability']??null)==='printify.catalog.read'&&is_int($context['integration_id']??null));
   self::require(PrintifyConnectionBoundary::authorize($context['integration_id'],'printify.catalog.read',$context)===true);
   $identity=$packet['identity'];self::require(is_array($identity)&&self::keys($identity,['product_id','blueprint_id','provider_id','variant_id','placement']));
   self::require(self::token($identity['product_id'])&&self::token($identity['placement']));foreach(['blueprint_id','provider_id','variant_id']as $k)self::require(is_int($identity[$k])&&$identity[$k]>0);
   $reviewId=self::reviewIdentity($packet);$review=ReviewedLocalEvidence::approved('policy','personalized_pod',$reviewId['key']);
   self::require(!is_wp_error($review)&&self::keys($review['payload'],array_keys($reviewId['payload'])));foreach($reviewId['payload']as $field=>$value)self::require($review['payload'][$field]===$value);
   $sources=self::sources($packet['sources'],$bytes);self::require(is_array($packet['sections'])&&self::keys($packet['sections'],self::CHECKS));
   foreach(self::CHECKS as $key){
    $section=$packet['sections'][$key];
    try{
     self::require(is_array($section)&&self::keys($section,['reported_level','source_ids','findings'])&&in_array($section['reported_level'],['DOCUMENTED','OBSERVED','TESTED'],true));
     $refs=$section['source_ids'];self::require(is_array($refs)&&array_is_list($refs)&&count($refs)>0&&count($refs)<=8&&count(array_unique($refs,SORT_REGULAR))===count($refs));
     foreach($refs as $ref){self::require(is_string($ref)&&isset($sources[$ref]));$kind=$sources[$ref]['kind'];self::require($packet['evidence_kind']==='SYNTHETIC_TEST_ONLY'||$kind!=='SYNTHETIC_TEST_ONLY');self::require($section['reported_level']==='DOCUMENTED'||$kind!=='PUBLIC_DOCUMENTATION');}
     self::finding($key,$section['findings'],$identity,$refs,$sources,$bytes);
     $out['sections'][$key]=['state'=>'MATCHED_REVIEWED_DECLARATION','reported_level'=>$section['reported_level'],'purpose_verified'=>false,'synthetic_only'=>$packet['evidence_kind']==='SYNTHETIC_TEST_ONLY'];
    }catch(\Throwable){/* An incomplete section never borrows a sibling's evidence. */}
   }
   // Read-only freshness checks reject revocation/drift during inspection, not just on retry.
   self::require(ReviewedLocalEvidence::approved('policy','personalized_pod',$reviewId['key'])===$review);
   self::require(PrintifyConnectionBoundary::authorize($context['integration_id'],'printify.catalog.read',$context)===true);
   foreach($sources as $source)self::require(self::time($source['expires_at'])>time());
   $out['matched_section_count']=count(array_filter($out['sections'],static fn(array $v):bool=>$v['state']==='MATCHED_REVIEWED_DECLARATION'));
   return $out;
  }catch(\Throwable){$out['sections']=array_fill_keys(self::CHECKS,['state'=>'UNKNOWN']);$out['matched_section_count']=0;return $out;}
 }
 private static function sources(mixed $list,array $bytes):array {
  self::require(is_array($list)&&array_is_list($list)&&count($list)>0&&count($list)<=16&&count($bytes)===count($list));$sources=[];$total=0;
  foreach($list as $s){
   self::require(is_array($s)&&self::keys($s,['id','kind','origin','observed_at','expires_at','sha256'])&&self::token($s['id'])&&!isset($sources[$s['id']]));
   self::require(in_array($s['kind'],['PUBLIC_DOCUMENTATION','DASHBOARD_CAPTURE','RENDER_CAPTURE','SYNTHETIC_TEST_ONLY'],true));
   self::require(is_string($s['origin'])&&strlen($s['origin'])<=512);$url=parse_url($s['origin']);self::require(is_array($url)&&($url['scheme']??null)==='https'&&in_array($url['host']??null,['printify.com','help.printify.com','developers.printify.com'],true)&&!array_intersect(['user','pass','query','fragment','port'],array_keys($url)));
   $observed=self::time($s['observed_at']);$expires=self::time($s['expires_at']);self::require($observed<=time()&&$expires>time()&&$observed<$expires);
   $body=$bytes[$s['id']]??null;self::require(is_string($body)&&strlen($body)>0&&strlen($body)<=262144);$total+=strlen($body);self::require($total<=1048576&&self::digest($s['sha256'])&&hash_equals($s['sha256'],hash('sha256',$body)));$sources[$s['id']]=$s;
  }
  self::require(array_diff(array_keys($bytes),array_keys($sources))===[]);return $sources;
 }
 private static function finding(string $key,mixed $d,array $identity,array $refs,array $sources,array $bytes):void {
  self::require(is_array($d));$fields=match($key){
   'N01'=>['font_selection','layer_settings_sha256'],'N02'=>['family_name_field_id','required'],
   'N03'=>['established_field_id','optional','conditional_omission','prefix'],'N04'=>['max_code_points','exact_utf8','normalization'],
   'N05'=>['overflow','auto_shrink'],'N06'=>['proposed_locale','glyph_coverage_sha256'],'N07'=>['geometry_sha256','placement'],
   'N08'=>['input_sha256','preview_sha256','final_sha256','parity_rule','preview_source_id','final_source_id'],default=>throw new \InvalidArgumentException('Unsupported evidence section.')};
  self::require(self::keys($d,$fields));
  switch($key){
   case 'N01':self::require(is_string($d['font_selection'])&&strlen($d['font_selection'])<=128&&preg_match('/^[^\x00-\x1f\x7f]+$/uD',$d['font_selection'])===1&&self::digest($d['layer_settings_sha256']));break;
   case 'N02':self::require(self::token($d['family_name_field_id'])&&$d['required']===true);break;
   case 'N03':self::require(self::token($d['established_field_id'])&&$d['optional']===true&&$d['conditional_omission']===true&&$d['prefix']==='ESTABLISHED ');break;
   case 'N04':self::require($d['max_code_points']===48&&$d['exact_utf8']===true&&$d['normalization']==='NONE');break;
   case 'N05':self::require($d['overflow']==='REJECT'&&$d['auto_shrink']===false);break;
   case 'N06':self::require($d['proposed_locale']==='en-US'&&self::digest($d['glyph_coverage_sha256']));break;
   case 'N07':self::require(self::digest($d['geometry_sha256'])&&$d['placement']===$identity['placement']);break;
   case 'N08':
    self::require(self::digest($d['input_sha256'])&&self::digest($d['preview_sha256'])&&$d['preview_sha256']===$d['final_sha256']&&$d['parity_rule']==='SAME_FORMAT_BYTES');
    foreach(['preview','final']as $role){$id=$d[$role.'_source_id'];self::require(is_string($id)&&in_array($id,$refs,true)&&isset($sources[$id])&&$sources[$id]['kind']==='RENDER_CAPTURE'&&$sources[$id]['sha256']===$d[$role.'_sha256']);RasterArtworkCodec::decode($bytes[$id]);}break;
  }
 }
 private static function time(mixed $value):int {self::require(is_string($value)&&strlen($value)<=40&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D',$value)===1);$date=new \DateTimeImmutable($value);$errors=\DateTimeImmutable::getLastErrors();self::require($errors===false||($errors['warning_count']===0&&$errors['error_count']===0));return $date->getTimestamp();}
 private static function token(mixed $v):bool{return is_string($v)&&preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$v)===1;}
 private static function digest(mixed $v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;}
 private static function keys(array $a,array $fields):bool{$keys=array_keys($a);sort($keys);sort($fields);return $keys===$fields;}
 private static function require(bool $ok):void {if(!$ok)throw new \InvalidArgumentException('Offline evidence is incomplete or unavailable.');}
 private static function json(array $a):string{return json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
}
