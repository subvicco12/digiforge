<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Pixel-preserving crop after orientation normalization. Never rescales or certifies print geometry. */
final class PhotoCrop
{
 public static function apply(string $png,array $rectangle):array {
  if(count($rectangle)!==4||array_diff(array_keys($rectangle),['x','y','width','height']))throw new \InvalidArgumentException('Exact crop rectangle required.');
  foreach($rectangle as $v)if(!is_int($v))throw new \InvalidArgumentException('Integer normalized-pixel crop required.');
  $p=RasterArtworkCodec::decode($png);$r=$rectangle;
  if($p['width_px']*$p['height_px']>1048576||$r['x']<0||$r['y']<0||$r['width']<1||$r['height']<1||$r['width']>$p['width_px']||$r['height']>$p['height_px']||$r['x']>$p['width_px']-$r['width']||$r['y']>$p['height_px']-$r['height'])throw new \InvalidArgumentException('Crop exceeds supported normalized image.');
  $rgba='';for($y=$r['y'];$y<$r['y']+$r['height'];$y++)$rgba.=substr($p['rgba'],($y*$p['width_px']+$r['x'])*4,$r['width']*4);
  $out=RasterArtworkCodec::encode($r['width'],$r['height'],$rgba);
  return ['png'=>$out,'provenance'=>['version'=>'photo-crop-v1','source_sha256'=>hash('sha256',$png),'result_sha256'=>hash('sha256',$out),'source_size_px'=>[$p['width_px'],$p['height_px']],'rectangle_px'=>$r,'resampled'=>false,'external_execution_authorized'=>false,'print_certified'=>false]];
 }
}
