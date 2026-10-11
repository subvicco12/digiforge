<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Local checks against geometry retained in the exact human-reviewed template. */
final class ArtworkGeometryQa
{
 public static function policy(array $area):array
 {
  if(!array_key_exists('qa_geometry',$area))return ['state'=>'MISSING_GEOMETRY','supplier_certified'=>false];
  $qa=$area['qa_geometry'];
  if(!is_array($qa)||count($qa)!==3||array_diff(array_keys($qa),['safe_zone','seam_exclusions','min_image_dpi'])!==[]||!is_int($qa['min_image_dpi']??null)||$qa['min_image_dpi']<72||$qa['min_image_dpi']>1200||!is_int($area['dpi']??null)||$area['dpi']<72||$area['dpi']>1200)throw new \InvalidArgumentException('Exact bounded QA geometry and template resolution required.');
  $safe=self::box($qa['safe_zone']??null,$area);
  $seams=$qa['seam_exclusions']??null;if(!is_array($seams)||!array_is_list($seams)||count($seams)>32)throw new \InvalidArgumentException('Explicit bounded seam exclusions required.');
  foreach($seams as &$seam)$seam=self::box($seam,$area);unset($seam);
  return ['state'=>'VERIFIED_LOCAL_GEOMETRY','safe_zone'=>$safe,'seam_exclusions'=>$seams,'min_image_dpi'=>$qa['min_image_dpi'],'dpi'=>$area['dpi'],'supplier_certified'=>false];
 }
 public static function placement(array $policy,array $bounds,?array $image=null):void
 {
  if(($policy['state']??null)==='MISSING_GEOMETRY')return;
  if(($policy['state']??null)!=='VERIFIED_LOCAL_GEOMETRY')throw new \InvalidArgumentException('Unknown QA state.');
  $safe=$policy['safe_zone'];
  foreach(['x','y','width','height']as $key)if(!is_int($bounds[$key]??null)||$bounds[$key]<($key==='x'||$key==='y'?0:1))throw new \InvalidArgumentException('Exact integer content bounds required.');
  if($bounds['x']<$safe['x']||$bounds['y']<$safe['y']||$bounds['x']+$bounds['width']>$safe['x']+$safe['width']||$bounds['y']+$bounds['height']>$safe['y']+$safe['height'])throw new \InvalidArgumentException('Personalized content escapes reviewed safe zone.');
  foreach($policy['seam_exclusions']as $seam)if($bounds['x']<$seam['x']+$seam['width']&&$bounds['x']+$bounds['width']>$seam['x']&&$bounds['y']<$seam['y']+$seam['height']&&$bounds['y']+$bounds['height']>$seam['y'])throw new \InvalidArgumentException('Personalized content intersects reviewed seam exclusion.');
  if($image!==null){foreach(['width_px','height_px']as $key)if(!is_int($image[$key]??null)||$image[$key]<1)throw new \InvalidArgumentException('Decoded source pixel geometry required.');if($image['width_px']*$policy['dpi']<$bounds['width']*$policy['min_image_dpi']&&$image['height_px']*$policy['dpi']<$bounds['height']*$policy['min_image_dpi'])throw new \InvalidArgumentException('Actual source resolution is below reviewed placement minimum.');}
 }
 private static function box(mixed $box,array $area):array
 {
  if(!is_array($box)||count($box)!==4||array_diff(array_keys($box),['x','y','width','height'])!==[])throw new \InvalidArgumentException('Exact pixel box required.');
  foreach(['x','y','width','height']as $key)if(!is_int($box[$key]??null)||$box[$key]<($key==='x'||$key==='y'?0:1)||$box[$key]>10000)throw new \InvalidArgumentException('Bounded integer pixel box required.');
  if(!is_int($area['width_px']??null)||!is_int($area['height_px']??null)||$box['x']+$box['width']>$area['width_px']||$box['y']+$box['height']>$area['height_px'])throw new \InvalidArgumentException('QA geometry escapes exact template panel.');
  return $box;
 }
}
