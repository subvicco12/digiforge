<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Orders\Validator;

/** Owner-authorized offline technical recipe. No segmentation, aesthetic or print certification. */
final class PetCutoutCompositor
{
 public const ID='PET_SOURCE_FAITHFUL_CUTOUT_001';
 public const VERSION='0.1.0';
 public const CONTRACT_SHA256='8dacaf17b27ce59bce0f630cf8db32cf96ffc70e337bafd5473aedbc8e765edc';
 public static function parameters(array $p):array
 {
  TransformationEngineContract::exact($p,['recipe_id','recipe_version','crop','canvas','placement','reduction']);
  if(($p['recipe_id']??self::ID)!==self::ID||($p['recipe_version']??self::VERSION)!==self::VERSION)throw new \InvalidArgumentException('Exact supported offline recipe required.');
  foreach(['crop'=>4,'canvas'=>2,'placement'=>2]as$f=>$n){if(!is_array($p[$f]??null)||!array_is_list($p[$f])||count($p[$f])!==$n)throw new \InvalidArgumentException('Explicit integer rectangles required.');foreach($p[$f]as$v)if(!is_int($v)||$v<0||$v>10000)throw new \InvalidArgumentException('Bounded integer coordinates required.');}
  $r=$p['reduction']??1;if(!is_int($r)||!in_array($r,[1,2,4],true))throw new \InvalidArgumentException('Reduction factor unsupported.');return ['recipe_id'=>self::ID,'recipe_version'=>self::VERSION,'crop'=>$p['crop'],'canvas'=>$p['canvas'],'placement'=>$p['placement'],'reduction'=>$r];
 }
 public static function matte(string $png,int $w,int $h,array $limits):string
 {
  $limits=TransformationEngineContract::limits($limits);
  if(strlen($png)>$limits['max_input_bytes']||strlen($png)<33||ord($png[24])!==8||ord($png[25])!==0)throw new \InvalidArgumentException('Explicit single-channel eight-bit grayscale PNG matte required.');
  $m=RasterArtworkCodec::decode($png);if($m['width_px']!==$w||$m['height_px']!==$h||$w*$h>$limits['max_pixels'])throw new \InvalidArgumentException('Matte dimensions must match prepared source.');
  $bytes='';for($i=0,$n=$w*$h;$i<$n;$i++)$bytes.=$m['rgba'][$i*4];return $bytes;
 }
 public static function render(string $prepared,string $matte,array $parameters,array $limits,string $scope='SYNTHETIC_TEST_ONLY'):array
 {
  $started=hrtime(true);$limits=TransformationEngineContract::limits($limits);$p=self::parameters($parameters);
  if(!in_array($scope,['SYNTHETIC_TEST_ONLY','OFFLINE_TECHNICAL_ONLY'],true)||strlen($prepared)>$limits['max_input_bytes'])throw new \InvalidArgumentException('Bounded offline input only.');
  $source=RasterArtworkCodec::decode($prepared);$mask=self::matte($matte,$source['width_px'],$source['height_px'],$limits);
  $pixels=self::compose($source['rgba'],$source['width_px'],$source['height_px'],$mask,$p,$limits,$started+$limits['max_execution_seconds']*1000000000);
  $png=RasterArtworkCodec::encode($pixels['width_px'],$pixels['height_px'],$pixels['rgba']);
  if(strlen($png)>$limits['max_output_bytes']||(hrtime(true)-$started)/1e9>$limits['max_execution_seconds'])throw new \InvalidArgumentException('Render exceeds existing explicit job bounds.');
  $implementation=@hash_file('sha256',__FILE__);if(!is_string($implementation))throw new \InvalidArgumentException('Exact backend source identity unavailable.');
  $backend=['php'=>PHP_VERSION,'zlib'=>ZLIB_VERSION,'codec'=>'bounded-png-v1','implementation_sha256'=>$implementation];
  $receipt=['recipe_id'=>self::ID,'recipe_version'=>self::VERSION,'source_contract_sha256'=>self::CONTRACT_SHA256,'recipe_sha256'=>hash('sha256',self::CONTRACT_SHA256.'|'.$backend['implementation_sha256']),'prepared_sha256'=>hash('sha256',$prepared),'matte_sha256'=>hash('sha256',$matte),'parameters'=>$p,'parameters_sha256'=>hash('sha256',Validator::canonicalJson($p)),'output_sha256'=>hash('sha256',$png),'pixel_sha256'=>$pixels['pixel_sha256'],'backend'=>$backend,'backend_sha256'=>hash('sha256',Validator::canonicalJson($backend)),'limits_sha256'=>hash('sha256',Validator::canonicalJson($limits)),'scope'=>$scope,'semantic_subject_count'=>'UNKNOWN','segmentation_executed'=>false,'commercial_quality_certified'=>false,'supplier_certified'=>false,'production_ready'=>false];
  $receipt['receipt_sha256']=hash('sha256',Validator::canonicalJson($receipt));return ['png'=>$png,'receipt'=>$receipt];
 }
 private static function compose(string $rgba,int $sw,int $sh,string $matte,array $p,array $limits,int $deadline):array
 {
  [$x,$y,$w,$h]=$p['crop'];[$cw,$ch]=$p['canvas'];[$px,$py]=$p['placement'];$r=$p['reduction'];RasterArtworkCodec::geometry($sw,$sh);RasterArtworkCodec::geometry($cw,$ch);
  if($sw*$sh>$limits['max_pixels']||$cw*$ch>$limits['max_pixels']||$w<1||$h<1||$x+$w>$sw||$y+$h>$sh||$w%$r||$h%$r)throw new \InvalidArgumentException('Crop, alignment or work bound invalid.');
  $ow=intdiv($w,$r);$oh=intdiv($h,$r);if($px+$ow>$cw||$py+$oh>$ch)throw new \InvalidArgumentException('Placement rectangle must not clip.');
  $support=false;for($i=0,$n=$sw*$sh;$i<$n;$i++){if(($i%1024)===0&&hrtime(true)>$deadline)throw new \InvalidArgumentException('Existing job deadline exceeded.');$a=intdiv(ord($rgba[$i*4+3])*ord($matte[$i])+127,255);if($a){$support=true;$sx=$i%$sw;$sy=intdiv($i,$sw);if($sx<$x||$sx>=$x+$w||$sy<$y||$sy>=$y+$h)throw new \InvalidArgumentException('Crop excludes nonzero subject support.');}}
  if(!$support)throw new \InvalidArgumentException('Empty effective subject.');$out=str_repeat("\0",$cw*$ch*4);$n=$r*$r;
  for($yy=0;$yy<$oh;$yy++)for($xx=0;$xx<$ow;$xx++){if($xx===0&&hrtime(true)>$deadline)throw new \InvalidArgumentException('Existing job deadline exceeded.');
   $s=0;$red=0;$green=0;$blue=0;
   for($dy=0;$dy<$r;$dy++)for($dx=0;$dx<$r;$dx++){$i=($y+$yy*$r+$dy)*$sw+$x+$xx*$r+$dx;$o=$i*4;$a=intdiv(ord($rgba[$o+3])*ord($matte[$i])+127,255);$s+=$a;$red+=ord($rgba[$o])*$a;$green+=ord($rgba[$o+1])*$a;$blue+=ord($rgba[$o+2])*$a;}
   $a=intdiv($s+intdiv($n,2),$n);if($s&&$a){$o=(($py+$yy)*$cw+$px+$xx)*4;$out[$o]=chr(intdiv($red+intdiv($s,2),$s));$out[$o+1]=chr(intdiv($green+intdiv($s,2),$s));$out[$o+2]=chr(intdiv($blue+intdiv($s,2),$s));$out[$o+3]=chr($a);}
  }
  return ['width_px'=>$cw,'height_px'=>$ch,'rgba'=>$out,'pixel_sha256'=>hash('sha256',$out)];
 }
 public static function preview(string $retainedFinal,int $factor,array $limits):array
 {
  $started=hrtime(true);$limits=TransformationEngineContract::limits($limits);if(strlen($retainedFinal)>$limits['max_output_bytes']||!in_array($factor,[1,2,4],true))throw new \InvalidArgumentException('Bounded retained final and supported factor required.');$s=RasterArtworkCodec::decode($retainedFinal);$w=$s['width_px'];$h=$s['height_px'];
  if($w*$h>$limits['max_pixels']||$w%$factor||$h%$factor)throw new \InvalidArgumentException('Preview alignment/bound invalid.');
  if((hrtime(true)-$started)/1e9>$limits['max_execution_seconds'])throw new \InvalidArgumentException('Preview deadline exceeded.');
  $p=['crop'=>[0,0,$w,$h],'canvas'=>[intdiv($w,$factor),intdiv($h,$factor)],'placement'=>[0,0],'reduction'=>$factor];
  if($factor===1){$png=$retainedFinal;$pixelHash=$s['pixel_sha256'];}else{$a=self::compose($s['rgba'],$w,$h,str_repeat("\xff",$w*$h),self::parameters($p),$limits,$started+$limits['max_execution_seconds']*1000000000);$png=RasterArtworkCodec::encode($a['width_px'],$a['height_px'],$a['rgba']);$pixelHash=$a['pixel_sha256'];}
  if(strlen($png)>$limits['max_output_bytes']||(hrtime(true)-$started)/1e9>$limits['max_execution_seconds'])throw new \InvalidArgumentException('Preview byte/time bound exceeded.');
  $implementation=@hash_file('sha256',__FILE__);if(!is_string($implementation))throw new \InvalidArgumentException('Preview backend identity unavailable.');
  $d=['version'=>'pet-final-preview-v1','final_sha256'=>hash('sha256',$retainedFinal),'final_pixel_sha256'=>$s['pixel_sha256'],'preview_sha256'=>hash('sha256',$png),'preview_pixel_sha256'=>$pixelHash,'factor'=>$factor,'algorithm'=>'PREMULTIPLIED_INTEGER_BOX_0.1.0','parameters_sha256'=>hash('sha256',Validator::canonicalJson($p)),'backend_sha256'=>hash('sha256',PHP_VERSION.'|'.ZLIB_VERSION.'|'.$implementation),'source'=>'RETAINED_FINAL_PIXELS','production_ready'=>false];$d['receipt_sha256']=hash('sha256',Validator::canonicalJson($d));return ['png'=>$png,'final_sha256'=>$d['final_sha256'],'pixel_sha256'=>$pixelHash,'receipt'=>$d];
 }
}
