<?php
declare(strict_types=1);
use DigiForge\POD\PetCutoutCompositor as C;
use DigiForge\POD\RasterArtworkCodec as R;
foreach(['Orders/Validator','POD/RasterArtworkCodec','POD/TransformationEngineContract','POD/PetCutoutCompositor']as$f){$p=dirname(__DIR__,2).'/includes/'.$f.'.php';if(is_file($p))require_once $p;}
final class PetCutoutCompositorTest extends PHPUnit\Framework\TestCase
{
 public static function limits():array{return ['max_input_bytes'=>16777216,'max_output_bytes'=>16777216,'max_pixels'=>4096,'max_execution_seconds'=>30,'max_variants'=>1,'max_retries'=>0];}
 public static function packed(array $a):string{return implode('',array_map(static fn($p)=>implode('',array_map('chr',$p)),$a));}
 public static function matte(array $a,int $w=2,int $h=2,int $depth=8):string{$chunk=static fn($t,$d)=>pack('N',strlen($d)).$t.$d.hash('crc32b',$t.$d,true);$data='';for($y=0;$y<$h;$y++){$data.="\0";for($x=0;$x<$w;$x++)$data.=$depth===8?chr($a[$y*$w+$x]):pack('n',$a[$y*$w+$x]);}return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$w,$h,$depth,0,0,0,0)).$chunk('IDAT',gzcompress($data,9)).$chunk('IEND','');}
 private function base(array $changes=[]):array{$source=[[255,0,0,255],[0,255,0,128],[0,0,255,0],[50,100,150,255]];return array_replace(['source'=>$source,'matte'=>[255,128,255,0],'crop'=>[0,0,2,2],'canvas'=>[4,4],'placement'=>[1,1],'reduction'=>1],$changes);}
 private function render(array $a):array{$png=R::encode(2,2,self::packed($a['source']));$params=array_intersect_key($a,array_flip(['crop','canvas','placement','reduction','text']));return C::render($png,self::matte($a['matte']),$params,self::limits(),$a['scope']??'SYNTHETIC_TEST_ONLY');}
 /** @dataProvider cases */
 public function testAllSuppliedReferenceCasesAgainstPhp(string $name):void
 {
  self::assertTrue(class_exists(C::class),'Actual cutout compositor missing.');$base=$this->base();
  $reject=match($name){'mask_dimension_mismatch'=>['matte'=>[255]],'crop_outside_image'=>['crop'=>[0,0,3,2]],'subject_crop_clipping'=>['crop'=>[1,0,1,2]],'placement_clipping'=>['placement'=>[3,3]],'empty_mask'=>['matte'=>[0,0,0,0]],'invalid_matte_depth'=>['depth'=>16],'unapproved_text'=>['text'=>'Dog mom'],'float_scale_or_coordinates'=>['placement'=>[1.0,1]],'unsupported_resampler'=>['reduction'=>3],'fractional_box_alignment'=>['reduction'=>4],'production_flag_not_authority'=>['scope'=>'PRODUCTION'],'reference_work_limit'=>['canvas'=>[65,64]],default=>null};
  if($reject!==null){$this->expectException(InvalidArgumentException::class);if($name==='mask_dimension_mismatch'){C::render(R::encode(2,2,self::packed($base['source'])),self::matte([255],1,1),array_intersect_key($base,array_flip(['crop','canvas','placement','reduction'])),self::limits());return;}if($name==='invalid_matte_depth'){C::render(R::encode(2,2,self::packed($base['source'])),self::matte([65535,65535,65535,65535],2,2,16),array_intersect_key($base,array_flip(['crop','canvas','placement','reduction'])),self::limits());return;}$this->render($this->base($reject));return;}
  if(in_array($name,['box_rounding_known_oracle','premultiplied_no_black_halo'],true)){$colors=$name==='box_rounding_known_oracle'?[[255,0,0,255],[0,255,0,255],[0,0,255,255],[255,255,255,255]]:[[255,0,0,255],[0,255,0,0],[0,0,255,0],[255,255,255,0]];$r=$this->render($this->base(['source'=>$colors,'matte'=>[255,255,255,255],'canvas'=>[1,1],'placement'=>[0,0],'reduction'=>2]));self::assertSame($name==='box_rounding_known_oracle'?"\x80\x80\x80\xff":"\xff\0\0\x40",R::decode($r['png'])['rgba']);return;}
  $r=$this->render($base);if($name==='transparent_canvas')self::assertSame($r['png'],$this->render($base)['png']);$rgba=R::decode($r['png'])['rgba'];$fixture=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/pet-cutout/SYNTHETIC_COMPOSITION_FIXTURE.json'),true);
  if($name==='preview_downsample_from_retained_final'){$p=C::preview($r['png'],2,self::limits());self::assertSame(self::packed($fixture['preview_rgba']),R::decode($p['png'])['rgba']);}
  elseif($name==='preview_identity_uses_final')self::assertSame($r['png'],C::preview($r['png'],1,self::limits())['png']);
  elseif($name==='retained_pixels_replay')self::assertSame($r,$this->render($base));
  else {self::assertSame(self::packed($fixture['expected_rgba']),$rgba);self::assertSame($fixture['expected_pixel_sha256'],hash('sha256',$rgba));}
 }
 public static function cases():array{$rows=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/pet-cutout/EXECUTED_REFERENCE_RESULTS.json'),true);return array_map(static fn($r)=>[$r['case']],$rows['results']);}
 public function testMalformedMatteAndResourceBoundsCannotBeReinterpreted():void
 {
  $source=R::encode(2,2,str_repeat("\xff",16));$good=self::matte([255,128,0,255]);$p=['crop'=>[0,0,2,2],'canvas'=>[2,2],'placement'=>[0,0],'reduction'=>1];
  foreach([$source,substr($good,0,-1),$good.'extra',str_repeat('x',16777217)]as$bad){try{C::render($source,$bad,$p,self::limits());self::fail('Malformed execution matte accepted.');}catch(InvalidArgumentException){self::assertTrue(true);}}
  foreach(['max_pixels'=>1,'max_input_bytes'=>1,'max_output_bytes'=>1]as$key=>$value){$limits=self::limits();$limits[$key]=$value;try{C::render($source,$good,$p,$limits);self::fail('Resource bound ignored.');}catch(InvalidArgumentException){self::assertTrue(true);}}
 }
 public function testAlphaBoundariesReductionFourAndUnsupportedOptions():void
 {
  self::assertTrue(class_exists(C::class),'Actual cutout compositor missing.');$params=['crop'=>[0,0,4,4],'canvas'=>[1,1],'placement'=>[0,0],'reduction'=>4];$png=R::encode(4,4,str_repeat("\xff\0\0\x01",16));$r=C::render($png,self::matte(array_fill(0,16,255),4,4),$params,self::limits());self::assertSame("\xff\0\0\x01",R::decode($r['png'])['rgba']);
  foreach(['text','rotation','mirror','scale']as$f){try{C::render($png,self::matte(array_fill(0,16,255),4,4),$params+[$f=>0],self::limits());self::fail('Unsupported option accepted.');}catch(InvalidArgumentException){self::assertTrue(true);}}
 }
}
