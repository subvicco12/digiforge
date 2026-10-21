<?php
declare(strict_types=1);
use DigiForge\POD\FC01TextOnlyConcept as TextConcept;
final class FC01TextOnlyConceptTest extends WP_UnitTestCase {
 private function input(array $changes=[]):array {return array_replace(['shop_key'=>'personalized_pod','family_id'=>'FC01','version'=>2,'design_variant'=>'text-only-noto-serif-v1','spec_sha256'=>TextConcept::identity()['spec_sha256'],'family_name'=>'Mira & Léon','established'=>'2011'],$changes);}
 public function testLicensedTextOnlyCandidateIsIndependentAndDeterministic():void {
  self::assertTrue(class_exists(TextConcept::class),'Independent text-only FC01 renderer required.');$r=TextConcept::render($this->input());self::assertNotWPError($r);self::assertSame($r,TextConcept::render($this->input()));
  self::assertSame(['header'=>'OUR FAMILY','family_name'=>'Mira & Léon','established'=>'ESTABLISHED 2011'],$r['text_runs']);self::assertSame('SIL-OFL-1.1',$r['font_license']);self::assertSame('2.007',$r['font_version']);self::assertSame('UNVERIFIED',$r['acceptance_state']);self::assertSame('UNKNOWN',$r['production_template_binding']);self::assertFalse($r['production_authorized']);self::assertFalse($r['rights_certified']);
  self::assertStringNotContainsString('<text',$r['svg']);self::assertStringNotContainsString('ornament',$r['svg']);self::assertSame(['header','family_name','established'],array_keys($r['regions']));
  foreach(['svg','png']as $f){self::assertSame($r[$f],$r['preview'][$f]);self::assertSame($r[$f],$r['final'][$f]);self::assertSame(hash('sha256',$r[$f]),$r[$f.'_sha256']);}
 }
 public function testExactTextOptionalOmissionAndUnsupportedShaping():void {
  foreach([''," \t\n",'　']as $empty){$r=TextConcept::render($this->input(['family_name'=>'  MiRa  ','established'=>$empty]));self::assertNotWPError($r);self::assertSame('  MiRa  ',$r['text_runs']['family_name']);self::assertArrayNotHasKey('established',$r['text_runs']);}
  $i=$this->input();unset($i['established']);self::assertNotWPError(TextConcept::render($i));$r=TextConcept::render($this->input(['established'=>' 2011 ']));self::assertSame('ESTABLISHED  2011 ',$r['text_runs']['established']);
  foreach(['Mira 😀',"Le\u{0301}on",'李']as $text)self::assertWPError(TextConcept::render($this->input(['family_name'=>$text])));
 }
 public function testCodePointLimitsAreSeparateFromPhysicalOverflow():void {
  self::assertNotWPError(TextConcept::render($this->input(['family_name'=>str_repeat('i',48)])));
  foreach(['family_name','established']as $field){self::assertWPError(TextConcept::render($this->input([$field=>str_repeat('i',49)])));self::assertWPError(TextConcept::render($this->input([$field=>str_repeat('W',48)])));}
  foreach(['','  ',"\xff","a\nb"]as $text)self::assertWPError(TextConcept::render($this->input(['family_name'=>$text])));
 }
 public function testScopeVersionFingerprintAndClaimsCannotAcquireAuthority():void {
  foreach([['shop_key'=>'digital'],['shop_key'=>'standard_pod'],['shop_key'=>'jewelry'],['version'=>1],['design_variant'=>'retained-option-a'],['spec_sha256'=>str_repeat('0',64)],['production_authorized'=>true],['locale'=>'en-US']]as $changes)self::assertWPError(TextConcept::render($this->input($changes)));
 }
 public function testEscapingAndInkStayInsideSeparateRegions():void {
  $r=TextConcept::render($this->input(['family_name'=>"<A&B's>"]));self::assertNotWPError($r);self::assertSame("<A&B's>",$r['text_runs']['family_name']);self::assertStringNotContainsString('<A&B',$r['svg']);
  foreach($r['ink_bounds']as $key=>$b){$a=$r['regions'][$key];self::assertGreaterThanOrEqual($a['x'],$b['x']);self::assertGreaterThanOrEqual($a['y'],$b['y']);self::assertLessThanOrEqual($a['x']+$a['width'],$b['x']+$b['width']);self::assertLessThanOrEqual($a['y']+$a['height'],$b['y']+$b['height']);}
 }
 public function testPinnedAssetsAndOriginalConceptRemainImmutable():void {
  $d=dirname(__DIR__,2).'/assets/pod-designs/fc01-v2-text-only/';$old=dirname(__DIR__,2).'/assets/pod-designs/fc01-v2/';$before=[];foreach(glob($old.'*')as $p)if(is_file($p))$before[$p]=hash_file('sha256',$p);
  $input=$this->input();foreach(['font/NotoSerif-Regular.ttf','font/OFL.txt','glyphs.json']as $file){$p=$d.$file;$b=file_get_contents($p);try{file_put_contents($p,$b.'changed');self::assertWPError(TextConcept::render($input));}finally{file_put_contents($p,$b);}}
  foreach($before as $p=>$sha)self::assertSame($sha,hash_file('sha256',$p));
 }
 public function testInvalidGeometryAndMissingNameFailClosed():void {
  $i=$this->input();unset($i['family_name']);self::assertWPError(TextConcept::render($i));
  $p=dirname(__DIR__,2).'/assets/pod-designs/fc01-v2-text-only/spec.json';$bytes=file_get_contents($p);
  foreach(['overlap','clipping','overflow','font_identity']as $case){$s=json_decode($bytes,true);if($case==='overlap')$s['regions']['family_name']['y']=110;elseif($case==='clipping')$s['regions']['family_name']['x']=880;elseif($case==='font_identity')$s['font_sha256']=str_repeat('0',64);else $s['regions']['family_name']['width']=1;$changed=wp_json_encode($s);$i=$this->input(['spec_sha256'=>hash('sha256',$changed)]);try{file_put_contents($p,$changed);self::assertWPError(TextConcept::render($i));}finally{file_put_contents($p,$bytes);}}
 }
 public function testCommittedSampleAndSameFormatParity():void {
  $input=$this->input(['family_name'=>'Avery & Noël','established'=>'2016']);$r=TextConcept::render($input);self::assertNotWPError($r);$root=dirname(__DIR__,2).'/assets/pod-designs/fc01-v2-text-only/';$target=getenv('DF_FC01_TEXT_FIXTURE_DIR');
  if($target){$hashes=[];foreach(['svg','png']as $format)foreach(['preview','final']as $kind){$file='sample-'.$kind.'.'.$format;file_put_contents($target.'/'.$file,$r[$format]);$hashes[$file]=hash('sha256',$r[$format]);}file_put_contents($target.'/provenance.json',wp_json_encode(['fictional_sample_only'=>true,'input'=>$input,'identity'=>$r['identity'],'renderer_sha256'=>hash_file('sha256',dirname(__DIR__,2).'/includes/POD/FC01TextOnlyConcept.php'),'font_license'=>'SIL-OFL-1.1','artifacts'=>$hashes,'proposed_review_locale'=>'en-US','locale_approved'=>false,'artistic_review_state'=>'MISSING','linguistic_review_state'=>'MISSING','rights_certified'=>false,'production_template_binding'=>'UNKNOWN','production_authorized'=>false,'acceptance_state'=>'UNVERIFIED'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");}
  else{$m=json_decode(file_get_contents($root.'provenance.json'),true);self::assertSame($m['renderer_sha256'],hash_file('sha256',dirname(__DIR__,2).'/includes/POD/FC01TextOnlyConcept.php'));foreach(['svg','png']as $format)foreach(['preview','final']as $kind){$file='sample-'.$kind.'.'.$format;self::assertSame($r[$format],file_get_contents($root.$file));self::assertSame($m['artifacts'][$file],hash_file('sha256',$root.$file));}}
 }
}
