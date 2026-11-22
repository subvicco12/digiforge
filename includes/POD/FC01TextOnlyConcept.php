<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Separate offline candidate; bundled licensed glyphs, no system-font fallback or provider binding. */
final class FC01TextOnlyConcept
{
 private const VARIANT='text-only-noto-serif-v1';
 private const FONT_SHA='c8f669ceb2c9c60ccf55198b305e08a997ffca79a38cc7eeb551e643cbe66505';
 private const LICENSE_SHA='0dab92d0544f7b233403f14b84a663bdbfa746982eda629e7f4f9ffe1b036feb';
 private static function root():string{return dirname(__DIR__,2).'/assets/pod-designs/fc01-v2-text-only/';}
 public static function identity():array {return self::load()['identity'];}
 public static function render(array $input):array|WP_Error {
  try{
   $d=self::load();$s=$d['spec'];$identity=$d['identity'];
   if(array_diff(array_keys($input),['shop_key','family_id','version','design_variant','spec_sha256','family_name','established'])!==[]||($input['shop_key']??null)!=='personalized_pod'||($input['family_id']??null)!=='FC01'||($input['version']??null)!==2||($input['design_variant']??null)!==self::VARIANT||!is_string($input['spec_sha256']??null)||!hash_equals($identity['spec_sha256'],$input['spec_sha256']))throw new \InvalidArgumentException('Exact offline identity required.');
   $name=self::text($input['family_name']??null,true);$established=self::text($input['established']??'',false);
   $runs=['header'=>'OUR FAMILY','family_name'=>$name];if(!self::blank($established))$runs['established']='ESTABLISHED '.$established;
   $svg='<svg xmlns="http://www.w3.org/2000/svg" width="900" height="700" viewBox="0 0 900 700">'."\n".'<rect x="0" y="0" width="900" height="700" fill="#f8f5f0"/>'."\n";$ink=[];
   foreach($runs as $key=>$text){
    $font=$d['atlas']['runs'][$key]??null;$box=$s['regions'][$key];
    if(!is_array($font))throw new \InvalidArgumentException('Exact glyph run required.');
    foreach(['ascent','descent']as $field)if(!is_int($font[$field]??null)||$font[$field]<0||$font[$field]>128)throw new \InvalidArgumentException('Bounded font metrics required.');
    $chars=preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY);$glyphs=[];$advance=0;
    foreach($chars as $char){$g=$font['glyphs'][$char]??null;if(!is_array($g))throw new \InvalidArgumentException('Unsupported glyph or shaping; substitution prohibited.');foreach(['advance','left','top','width','height']as $field)if(!is_int($g[$field]??null)||abs($g[$field])>128)throw new \InvalidArgumentException('Bounded glyph metrics required.');if($g['advance']<0||$g['width']<0||$g['height']<0)throw new \InvalidArgumentException('Invalid glyph metrics.');$glyphs[]=$g;$advance+=$g['advance'];}
    $lineHeight=$font['ascent']+$font['descent'];if($advance>$box['width']||$lineHeight>$box['height'])throw new \InvalidArgumentException('Overflow; no automatic shrinking.');
    $x=$box['x']+intdiv($box['width']-$advance,2);$baseline=$box['y']+intdiv($box['height']-$lineHeight,2)+$font['ascent'];$bounds=null;
    foreach($glyphs as $g){
     if($g['width']>0&&$g['height']>0){$gx=$x+$g['left'];$gy=$baseline+$g['top'];if($gx<$box['x']||$gy<$box['y']||$gx+$g['width']>$box['x']+$box['width']||$gy+$g['height']>$box['y']+$box['height'])throw new \InvalidArgumentException('Glyph ink would clip.');
      if(!is_string($g['png']??null)||strlen($g['png'])>32768)throw new \InvalidArgumentException('Bounded glyph image required.');$bytes=base64_decode($g['png'],true);if(!is_string($bytes))throw new \InvalidArgumentException('Invalid glyph image.');$pixels=RasterArtworkCodec::decode($bytes);if($pixels['width_px']!==$g['width']||$pixels['height_px']!==$g['height'])throw new \InvalidArgumentException('Glyph dimensions mismatch.');
      $svg.='<image x="'.$gx.'" y="'.$gy.'" width="'.$g['width'].'" height="'.$g['height'].'" preserveAspectRatio="xMidYMid meet" href="data:image/png;base64,'.$g['png'].'"/>'."\n";
      $bounds=$bounds===null?[$gx,$gy,$gx+$g['width'],$gy+$g['height']]:[min($bounds[0],$gx),min($bounds[1],$gy),max($bounds[2],$gx+$g['width']),max($bounds[3],$gy+$g['height'])];
     }$x+=$g['advance'];
    }
    if($bounds!==null)$ink[$key]=['x'=>$bounds[0],'y'=>$bounds[1],'width'=>$bounds[2]-$bounds[0],'height'=>$bounds[3]-$bounds[1]];
   }
   $svg.="</svg>\n";$converted=LocalArtworkAdapter::convert($svg,'png');if(is_wp_error($converted))return $converted;$png=$converted['bytes'];$payload=['family_name'=>$name];if(array_key_exists('established',$input))$payload['established']=$established;
   return ['svg'=>$svg,'png'=>$png,'preview'=>['svg'=>$svg,'png'=>$png],'final'=>['svg'=>$svg,'png'=>$png],'svg_sha256'=>hash('sha256',$svg),'png_sha256'=>hash('sha256',$png),'identity'=>$identity,'input_sha256'=>hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),'text_runs'=>$runs,'regions'=>$s['regions'],'ink_bounds'=>$ink,'font_license'=>'SIL-OFL-1.1','font_version'=>'2.007','proposed_review_locale'=>'en-US','locale_approved'=>false,'acceptance_state'=>'UNVERIFIED','linguistic_review_state'=>'MISSING','artistic_review_state'=>'MISSING','production_template_binding'=>'UNKNOWN','rights_certified'=>false,'supplier_certified'=>false,'production_authorized'=>false,'external_execution_authorized'=>false,'publishing_allowed'=>false];
  }catch(\Throwable){return new WP_Error('fc01_text_only_blocked','Exact offline FC01 identity, licensed pinned glyphs, supported UTF-8 and unclipped fixed-size text are required. No review or production authority is granted.',['status'=>409,'acceptance_state'=>'UNVERIFIED','external_execution_authorized'=>false]);}
 }
 private static function text(mixed $value,bool $required):string {
  if(!is_string($value)||strlen($value)>4096||preg_match('//u',$value)!==1||preg_match_all('/./us',$value)>48)throw new \InvalidArgumentException('Valid UTF-8, at most48 code points required.');
  if(self::blank($value)){if($required)throw new \InvalidArgumentException('Nonblank name required.');return $value;}
  if(preg_match('/[\x00-\x1f\x7f]/',$value))throw new \InvalidArgumentException('Control characters unsupported.');return $value;
 }
 private static function blank(string $value):bool{return preg_match('/^[\s\p{Z}]*$/uD',$value)===1;}
 private static function load():array {
  $r=self::root();$raw=self::bytes($r.'spec.json',32768);$s=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
  if(($s['schema']??null)!=='fc01-text-only-concept-v1'||($s['design_variant']??null)!==self::VARIANT||($s['family_id']??null)!=='FC01'||($s['version']??null)!==2||($s['shop_key']??null)!=='personalized_pod'||($s['state']??null)!=='OFFLINE_CONCEPT_REVIEW_REQUIRED'||($s['canvas']??null)!==['width'=>900,'height'=>700])throw new \InvalidArgumentException('Pinned offline source required.');
  if(($s['font_sha256']??null)!==self::FONT_SHA||($s['license_sha256']??null)!==self::LICENSE_SHA||($s['proposed_review_locale']??null)!=='en-US')throw new \InvalidArgumentException('Exact proposed font, license and locale identity required.');
  $regions=$s['regions']??null;if(!is_array($regions)||array_keys($regions)!==['header','family_name','established'])throw new \InvalidArgumentException('Text-only regions required.');
  foreach($regions as $a){if(!is_array($a)||array_keys($a)!==['x','y','width','height'])throw new \InvalidArgumentException('Exact regions required.');foreach($a as $n)if(!is_int($n))throw new \InvalidArgumentException('Integer regions required.');if($a['x']<0||$a['y']<0||$a['width']<1||$a['height']<1||$a['x']+$a['width']>900||$a['y']+$a['height']>700)throw new \InvalidArgumentException('Clipped region.');}
  $v=array_values($regions);for($i=0;$i<count($v);$i++)for($j=$i+1;$j<count($v);$j++){[$a,$b]=[$v[$i],$v[$j]];if($a['x']<$b['x']+$b['width']&&$b['x']<$a['x']+$a['width']&&$a['y']<$b['y']+$b['height']&&$b['y']<$a['y']+$a['height'])throw new \InvalidArgumentException('Overlapping regions.');}
  self::matches(self::FONT_SHA,self::bytes($r.'font/NotoSerif-Regular.ttf',1048576));self::matches(self::LICENSE_SHA,self::bytes($r.'font/OFL.txt',16384));
  $license=self::bytes($r.'font/provenance.json',16384);self::matches($s['font_provenance_sha256']??null,$license);$p=json_decode($license,true,16,JSON_THROW_ON_ERROR);if(($p['font_sha256']??null)!==self::FONT_SHA||($p['license_sha256']??null)!==self::LICENSE_SHA||($p['license']??null)!=='SIL-OFL-1.1'||($p['font_modified']??null)!==false)throw new \InvalidArgumentException('Font provenance mismatch.');
  $bytes=self::bytes($r.'glyphs.json',1048576);self::matches($s['glyphs_sha256']??null,$bytes);$atlas=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);if(($atlas['schema']??null)!=='fc01-noto-glyphs-v1'||($atlas['font_sha256']??null)!==self::FONT_SHA||($atlas['license_sha256']??null)!==self::LICENSE_SHA)throw new \InvalidArgumentException('Glyph provenance mismatch.');
  if(!is_array($s['retained_parent_sha256']??null)||$s['retained_parent_sha256']===[])throw new \InvalidArgumentException('Immutable parent required.');foreach($s['retained_parent_sha256']as $file=>$sha){if(!is_string($file)||basename($file)!==$file)throw new \InvalidArgumentException('Invalid parent identity.');self::matches($sha,self::bytes(dirname($r).'/fc01-v2/'.$file,1048576));}
  return ['spec'=>$s,'atlas'=>$atlas,'identity'=>['family_id'=>'FC01','version'=>2,'design_variant'=>self::VARIANT,'shop_key'=>'personalized_pod','spec_sha256'=>hash('sha256',$raw),'font_sha256'=>self::FONT_SHA,'license_sha256'=>self::LICENSE_SHA,'glyphs_sha256'=>$s['glyphs_sha256']]];
 }
 private static function bytes(string $path,int $limit):string {if(is_link($path))throw new \InvalidArgumentException('Symlinks unsupported.');$b=@file_get_contents($path,false,null,0,$limit+1);if(!is_string($b)||strlen($b)>$limit)throw new \InvalidArgumentException('Bounded source required.');return $b;}
 private static function matches(mixed $sha,string $bytes):void {if(!is_string($sha)||preg_match('/^[a-f0-9]{64}$/D',$sha)!==1||!hash_equals($sha,hash('sha256',$bytes)))throw new \InvalidArgumentException('Source hash mismatch.');}
}
