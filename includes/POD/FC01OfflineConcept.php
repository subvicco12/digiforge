<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Unregistered offline concept only. No production template, review authority, persistence or dispatch. */
final class FC01OfflineConcept
{
 private const PARENTS=['families.json','registry.json','sources/20_Personalized_Template_Previews/FC01_preview.svg','sources/20_Personalized_Template_Previews/FC01_preview.png','sources/POD_Reusable_Vector_Components/family_leaf_border.svg','components/family_leaf_border.png','sources/20_Personalized_Template_Previews/preview_manifest.csv'];
 private static function root():string{return dirname(__DIR__,2).'/assets/pod-designs/fc01-v2/';}
 public static function identity():array {return self::load()['identity'];}
 public static function render(array $input):array|WP_Error {
  try{
   $data=self::load();$s=$data['spec'];$id=$data['identity'];
   if(array_diff(array_keys($input),['shop_key','family_id','version','spec_sha256','family_name','established'])!==[]||($input['shop_key']??null)!=='personalized_pod'||($input['family_id']??null)!=='FC01'||($input['version']??null)!==2||!is_string($input['spec_sha256']??null)||!hash_equals($id['spec_sha256'],$input['spec_sha256']))throw new \InvalidArgumentException('Exact offline shop/family/version/source identity required; approval claims are unsupported.');
   $name=self::text($input['family_name']??null,true);$established=self::text($input['established']??'',false);
   $runs=['header'=>'OUR FAMILY','family_name'=>$name];if(!self::blank($established))$runs['established']='ESTABLISHED '.$established;
   $svg='<svg xmlns="http://www.w3.org/2000/svg" width="900" height="700" viewBox="0 0 900 700">'."\n".'<rect x="0" y="0" width="900" height="700" fill="#f8f5f0"/>'."\n";
   $o=$s['regions']['ornament'];$svg.='<image x="'.$o['x'].'" y="'.$o['y'].'" width="'.$o['width'].'" height="'.$o['height'].'" preserveAspectRatio="xMidYMid meet" href="data:image/png;base64,'.base64_encode($data['ornament']).'"/>'."\n";
   foreach($runs as $key=>$text){$box=$s['regions'][$key];$scale=$s['scales'][$key]??null;if(!is_int($scale)||$scale<1||$scale>8)throw new \InvalidArgumentException('Exact bounded concept font scale required.');$count=preg_match_all('/./us',$text);$width=$count*($data['font']['width']+1)*$scale;$height=$data['font']['height']*$scale;if($width>$box['width']||$height>$box['height'])throw new \InvalidArgumentException('Text would overflow or clip; no shrinking or truncation permitted.');$svg.=BitmapFontAdapter::rectangles($data['font'],$text,$box['x']+intdiv($box['width']-$width,2),$box['y']+intdiv($box['height']-$height,2),$scale,'#304e55');}
   $svg.="</svg>\n";$png=LocalArtworkAdapter::convert($svg,'png');if(is_wp_error($png))return $png;
   $payload=['family_name'=>$name];if(array_key_exists('established',$input))$payload['established']=$established;
   return ['svg'=>$svg,'png'=>$png['bytes'],'svg_sha256'=>hash('sha256',$svg),'png_sha256'=>hash('sha256',$png['bytes']),'identity'=>$id,'input_sha256'=>hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),'text_runs'=>$runs,'regions'=>$s['regions'],'proposed_review_locale'=>'en-US','locale_approved'=>false,'acceptance_state'=>'UNVERIFIED','linguistic_review_state'=>'MISSING','supplier_certified'=>false,'rights_certified'=>false,'production_authorized'=>false,'external_execution_authorized'=>false,'publishing_allowed'=>false];
  }catch(\Throwable){return new WP_Error('fc01_offline_concept_blocked','Exact bounded FC01 v2 inputs, supported glyphs and nonoverlapping concept geometry are required. No review or production authority is granted.',['status'=>409,'external_execution_authorized'=>false,'acceptance_state'=>'UNVERIFIED']);}
 }
 private static function text(mixed $value,bool $required):string {
  if(!is_string($value)||strlen($value)>4096||preg_match('//u',$value)!==1||preg_match_all('/./us',$value)>48)throw new \InvalidArgumentException('Exact valid UTF-8 text, maximum 48 code points required.');
  if(self::blank($value)){if($required)throw new \InvalidArgumentException('Nonblank family name required.');return $value;}
  if(preg_match('/[\x00-\x1f\x7f]/',$value))throw new \InvalidArgumentException('Control characters are unsupported.');return $value;
 }
 private static function blank(string $value):bool{return preg_match('/^[\s\p{Z}]*$/uD',$value)===1;}
 private static function load():array {
  $root=self::root();$raw=self::bytes($root.'spec.json',32768);$s=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
  if(!is_array($s)||($s['family_id']??null)!=='FC01'||($s['version']??null)!==2||($s['shop_key']??null)!=='personalized_pod'||($s['state']??null)!=='OFFLINE_CONCEPT_REVIEW_REQUIRED'||($s['concept_revision']??null)!==2||($s['canvas']??null)!==['width'=>900,'height'=>700]||($s['proposed_review_locale']??null)!=='en-US'||!is_array($s['parent_sources']??null)||array_keys($s['parent_sources'])!==self::PARENTS)throw new \InvalidArgumentException('Versioned offline source required.');
  $regions=$s['regions']??[];if(!is_array($regions)||array_keys($regions)!==['header','ornament','family_name','established'])throw new \InvalidArgumentException('Exact concept regions required.');
  foreach($regions as $a){if(!is_array($a)||array_keys($a)!==['x','y','width','height'])throw new \InvalidArgumentException('Exact region geometry required.');foreach($a as $v)if(!is_int($v))throw new \InvalidArgumentException('Integer regions required.');if($a['x']<0||$a['y']<0||$a['width']<1||$a['height']<1||$a['x']+$a['width']>900||$a['y']+$a['height']>700)throw new \InvalidArgumentException('Concept clipping prohibited.');}
  $values=array_values($regions);for($i=0;$i<count($values);$i++)for($j=$i+1;$j<count($values);$j++){$a=$values[$i];$b=$values[$j];if($a['x']<$b['x']+$b['width']&&$b['x']<$a['x']+$a['width']&&$a['y']<$b['y']+$b['height']&&$b['y']<$a['y']+$a['height'])throw new \InvalidArgumentException('Ornament and text overlap prohibited.');}
  foreach(self::PARENTS as $p)self::matches($s['parent_sources'][$p],self::bytes(dirname($root).'/v1/'.$p,65536));
  $font=self::bytes($root.'font.synthetic.json',65536);$ornament=self::bytes($root.'ornament.png',65536);self::matches($s['font_sha256']??null,$font);self::matches($s['ornament_png_sha256']??null,$ornament);self::matches($s['ornament_svg_sha256']??null,self::bytes($root.'ornament.svg',4096));
  return ['spec'=>$s,'font'=>BitmapFontAdapter::normalize(json_decode($font,true,16,JSON_THROW_ON_ERROR)),'ornament'=>$ornament,'identity'=>['concept_revision'=>2,'family_id'=>'FC01','version'=>2,'shop_key'=>'personalized_pod','spec_sha256'=>hash('sha256',$raw),'font_sha256'=>$s['font_sha256'],'ornament_svg_sha256'=>$s['ornament_svg_sha256'],'ornament_png_sha256'=>$s['ornament_png_sha256'],'parent_sources'=>$s['parent_sources']]];
 }
 private static function bytes(string $path,int $limit):string {if(is_link($path))throw new \InvalidArgumentException('Source symlinks unsupported.');$b=@file_get_contents($path,false,null,0,$limit+1);if(!is_string($b)||strlen($b)>$limit)throw new \InvalidArgumentException('Bounded immutable source required.');return $b;}
 private static function matches(mixed $expected,string $bytes):void {if(!is_string($expected)||preg_match('/^[a-f0-9]{64}$/D',$expected)!==1||!hash_equals($expected,hash('sha256',$bytes)))throw new \InvalidArgumentException('Source hash mismatch.');}
}
