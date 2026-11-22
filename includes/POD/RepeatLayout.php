<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Exact reviewed tile geometry; never extracts faces, crops assets or guesses seams. */
final class RepeatLayout
{
 public const MAX_TILES=256;
 public static function expand(array $slot,int $panelWidth,int $panelHeight):array
 {
  $repeat=$slot['repeat']??null;
  if(($slot['type']??null)!=='image'||!is_array($repeat)||!in_array(count($repeat),[4,5],true)||array_diff(array_keys($repeat),['columns','rows','gap_x','gap_y','rotations'])!==[])throw new \InvalidArgumentException('Exact image repeat descriptor required.');
  foreach(['columns','rows','gap_x','gap_y']as $key)if(!is_int($repeat[$key]??null)||$repeat[$key]<($key==='columns'||$key==='rows'?1:0)||$repeat[$key]>10000)throw new \InvalidArgumentException('Repeat geometry must use bounded integers.');
  $rotations=$repeat['rotations']??[0];if(!is_array($rotations)||!array_is_list($rotations)||count($rotations)<1||count($rotations)>16)throw new \InvalidArgumentException('Bounded rotation sequence required.');foreach($rotations as $rotation)if(!in_array($rotation,[0,90,180,270],true))throw new \InvalidArgumentException('Only lossless quarter turns supported.');
  foreach(['x','y','width','height']as $key)if(!is_int($slot[$key]??null)||$slot[$key]<($key==='width'||$key==='height'?1:0)||$slot[$key]>10000)throw new \InvalidArgumentException('Tile placement must use bounded integers.');
  if($repeat['columns']*$repeat['rows']>self::MAX_TILES)throw new \InvalidArgumentException('Repeat layout exceeds tile work limit.');
  $right=$slot['x']+$repeat['columns']*$slot['width']+($repeat['columns']-1)*$repeat['gap_x'];
  $bottom=$slot['y']+$repeat['rows']*$slot['height']+($repeat['rows']-1)*$repeat['gap_y'];
  if($panelWidth<1||$panelHeight<1||$right>$panelWidth||$bottom>$panelHeight)throw new \InvalidArgumentException('Repeat layout must fit exact approved panel without clipping.');
  $base=$slot;unset($base['repeat']);$tiles=[];
  for($row=0;$row<$repeat['rows'];$row++)for($column=0;$column<$repeat['columns'];$column++){$tile=$base;$tile['x']+=$column*($slot['width']+$repeat['gap_x']);$tile['y']+=$row*($slot['height']+$repeat['gap_y']);if(isset($repeat['rotations']))$tile['rotation']=$rotations[count($tiles)%count($rotations)];$tiles[]=$tile;}
  return $tiles;
 }
}
