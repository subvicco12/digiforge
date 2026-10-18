<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Deterministic styling of an explicitly supplied alpha mask. Never segments a photo. */
final class CutoutStyleAdapter
{
    public const VERSION = 'reviewed-alpha-style-v1';
    public const STYLES = ['CS01','CS02','CS03','CS04','CS05','CS06'];

    public static function apply(string $source, string $mask, string $style, array $options): string
    {
        $s = RasterArtworkCodec::decode($source); $m = RasterArtworkCodec::decode($mask);
        $w = $s['width_px']; $h = $s['height_px'];
        if ($w !== $m['width_px'] || $h !== $m['height_px'] || $w*$h > 1048576) throw new \InvalidArgumentException('Bounded matching source/mask pixel geometry required.');
        $allowed = match ($style) { 'CS01','CS06'=>[], 'CS02'=>['radius'], 'CS03'=>['radius','color'], 'CS04'=>['radius','dx','dy','opacity'], 'CS05'=>['border','color'], default=>throw new \InvalidArgumentException('Unsupported cutout style.') };
        if (count($options) !== count($allowed) || array_diff(array_keys($options),$allowed)) throw new \InvalidArgumentException('Exact explicit style options required.');
        $radius = $options['radius'] ?? $options['border'] ?? 0;
        if (!is_int($radius) || $radius<0 || $radius>16 || $w*$h*(2*$radius+1)**2>8388608) throw new \InvalidArgumentException('Styling exceeds bounded work budget.');
        $color = $style==='CS02' ? '#ffffff' : ($options['color'] ?? '#000000');
        if (!is_string($color) || !preg_match('/^#[a-f0-9]{6}$/D',$color)) throw new \InvalidArgumentException('Exact RGB style color required.');
        if ($style==='CS04') { foreach(['dx','dy'] as $n) if (!is_int($options[$n]) || abs($options[$n])>16) throw new \InvalidArgumentException('Bounded shadow offset required.'); if(!is_int($options['opacity']) || $options['opacity']<1 || $options['opacity']>255) throw new \InvalidArgumentException('Bounded shadow opacity required.'); }
        $rgba=$s['rgba']; $visible=0; $clear=0;
        for($y=0;$y<$h;$y++) for($x=0;$x<$w;$x++) {
            $i=($y*$w+$x)*4; $a=intdiv(ord($rgba[$i+3])*ord($m['rgba'][$i+3])+127,255); $rgba[$i+3]=chr($a);
            if($a>0){$visible++;if($x===0||$y===0||$x===$w-1||$y===$h-1)throw new \InvalidArgumentException('Cutout touches canvas edge; clipped-subject review required.');}else{$clear++;}
            if($style==='CS06'){$gray=chr(intdiv(77*ord($rgba[$i])+150*ord($rgba[$i+1])+29*ord($rgba[$i+2])+128,256));$rgba[$i]=$rgba[$i+1]=$rgba[$i+2]=$gray;}
        }
        if(!$visible||!$clear) throw new \InvalidArgumentException('Nonempty foreground and actual transparent background required.');
        $out=str_repeat("\0\0\0\0",$w*$h); $rgb=hex2bin(substr($color,1));
        for($y=0;$y<$h;$y++) for($x=0;$x<$w;$x++) {
            $alpha=0;
            if(in_array($style,['CS02','CS03','CS04'],true)) {
                $sum=0; $samples=0; $dx=$style==='CS04'?$options['dx']:0; $dy=$style==='CS04'?$options['dy']:0;
                for($yy=-$radius;$yy<=$radius;$yy++) for($xx=-$radius;$xx<=$radius;$xx++) { $sx=$x-$dx+$xx;$sy=$y-$dy+$yy; $a=$sx>=0&&$sy>=0&&$sx<$w&&$sy<$h?ord($rgba[($sy*$w+$sx)*4+3]):0;$alpha=max($alpha,$a);$sum+=$a;$samples++; }
                if($style==='CS04')$alpha=intdiv(intdiv($sum,$samples)*$options['opacity']+127,255);
                if($alpha>0&&($x===0||$y===0||$x===$w-1||$y===$h-1))throw new \InvalidArgumentException('Style escapes transparent padding; enlarge reviewed mask canvas.');
            } elseif($style==='CS05') {
                $distance=(2*$x+1-$w)**2+(2*$y+1-$h)**2;$outer=min($w,$h)-2;$inner=$outer-2*$radius;
                if($inner<1)throw new \InvalidArgumentException('Circle frame cannot fit.');
                if($distance>$inner*$inner && ord($rgba[($y*$w+$x)*4+3])>0)throw new \InvalidArgumentException('Subject would be clipped by reviewed circular frame.');
                if($distance<=$outer*$outer&&$distance>=$inner*$inner)$alpha=255;
            }
            $i=($y*$w+$x)*4;$foreground=ord($rgba[$i+3]);$combined=$foreground+intdiv($alpha*(255-$foreground)+127,255);
            for($c=0;$c<3;$c++)$out[$i+$c]=chr($combined?intdiv(ord($rgba[$i+$c])*$foreground+intdiv(ord($rgb[$c])*$alpha*(255-$foreground)+127,255)+intdiv($combined,2),$combined):0);
            $out[$i+3]=chr($combined);
        }
        return RasterArtworkCodec::encode($w,$h,$out);
    }

    public static function rotate(string $bytes, int $degrees): string
    {
        if(!in_array($degrees,[0,90,180,270],true))throw new \InvalidArgumentException('Only exact lossless quarter-turn rotations supported.');
        $image=RasterArtworkCodec::decode($bytes);if($degrees===0)return $bytes;$w=$image['width_px'];$h=$image['height_px'];$nw=$degrees===180?$w:$h;$nh=$degrees===180?$h:$w;$pixels=str_repeat("\0",$nw*$nh*4);
        for($y=0;$y<$h;$y++)for($x=0;$x<$w;$x++){[$nx,$ny]=match($degrees){90=>[$h-1-$y,$x],180=>[$w-1-$x,$h-1-$y],270=>[$y,$w-1-$x]};$src=($y*$w+$x)*4;$dst=($ny*$nw+$nx)*4;for($c=0;$c<4;$c++)$pixels[$dst+$c]=$image['rgba'][$src+$c];}
        return RasterArtworkCodec::encode($nw,$nh,$pixels);
    }
}
