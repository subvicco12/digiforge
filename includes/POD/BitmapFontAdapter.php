<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Actual reviewed glyph data travels with the immutable recipe; no host-font fallback. */
final class BitmapFontAdapter
{
    public static function normalize(array $font):array
    {
        if(array_diff(array_keys($font),['id','version','width','height','glyphs'])!==[]||!is_string($font['id']??null)||!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$font['id'])||!is_int($font['version']??null)||$font['version']<1)throw new \InvalidArgumentException('Exact versioned font identity required.');
        foreach(['width'=>16,'height'=>32]as $key=>$max)if(!is_int($font[$key]??null)||$font[$key]<1||$font[$key]>$max)throw new \InvalidArgumentException('Bounded glyph geometry required.');
        if(!is_array($font['glyphs']??null)||$font['glyphs']===[]||count($font['glyphs'])>256)throw new \InvalidArgumentException('Actual bounded glyph data required.');
        foreach($font['glyphs']as $glyph=>$rows){$glyph=(string)$glyph;if(preg_match('/^.$/usD',$glyph)!==1||preg_match('/[\x00-\x1f\x7f]/',$glyph)||!is_array($rows)||!array_is_list($rows)||count($rows)!==$font['height'])throw new \InvalidArgumentException('Glyph identity or rows invalid.');foreach($rows as $row)if(!is_string($row)||strlen($row)!==$font['width']||preg_match('/^[01]+$/D',$row)!==1)throw new \InvalidArgumentException('Actual bitmap glyph pixels required.');}
        ksort($font['glyphs']);return ['id'=>$font['id'],'version'=>$font['version'],'width'=>$font['width'],'height'=>$font['height'],'glyphs'=>$font['glyphs']];
    }
    public static function rectangles(array $font,string $text,int $x,int $y,int $scale,string $color):string
    {
        $font=self::normalize($font);if(strlen($text)>1024||$x<0||$y<0||$scale<1||$scale>32||!preg_match('/^#[a-f0-9]{6}$/D',$color)||preg_match('//u',$text)!==1)throw new \InvalidArgumentException('Bounded font placement required.');
        $svg='';$primitives=0;foreach(preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY)as $glyph){if(!array_key_exists($glyph,$font['glyphs']))throw new \InvalidArgumentException('Approved font has no actual glyph; substitution is prohibited.');foreach($font['glyphs'][$glyph]as $row=>$bits)for($column=0;$column<$font['width'];$column++)if($bits[$column]==='1'){if(++$primitives>8192)throw new \InvalidArgumentException('Glyph drawing exceeds bounded local capability.');$svg.='<rect x="'.($x+$column*$scale).'" y="'.($y+$row*$scale).'" width="'.$scale.'" height="'.$scale.'" fill="'.$color.'"/>' ."\n";}$x+=($font['width']+1)*$scale;}return $svg;
    }
}
