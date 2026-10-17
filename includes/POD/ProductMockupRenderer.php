<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Explicit supplied UV maps; never guesses product geometry or certifies physical prints. */
final class ProductMockupRenderer
{
    public const VERSION = 'rgba-uv16-premultiplied-v1';
    public const MAX_PREVIEW_PIXELS = 262144;
    private const SCALE = 65535;

    public static function validateLayers(array $layers):array
    {
        $keys = ['base','u','v','coverage','lighting'];
        if (PHP_INT_SIZE !== 8 || count($layers) !== count($keys) || array_diff(array_keys($layers),$keys)) {
            throw new \InvalidArgumentException('Exact bounded UV16 layers and 64-bit arithmetic required.');
        }
        $decoded = [];
        foreach ($keys as $key) {
            if (!is_string($layers[$key]) || strlen($layers[$key]) > 4500000) throw new \InvalidArgumentException('Bounded layer bytes required.');
            $decoded[$key] = RasterArtworkCodec::decode($layers[$key]);
            if ($decoded[$key]['width_px']*$decoded[$key]['height_px'] > self::MAX_PREVIEW_PIXELS) throw new \InvalidArgumentException('Digital preview canvas limit exceeded.');
            if ($decoded[$key]['width_px'] !== $decoded['base']['width_px'] || $decoded[$key]['height_px'] !== $decoded['base']['height_px']) throw new \InvalidArgumentException('All projected layers must share one exact canvas.');
        }
        $visible = 0;
        for ($i=0; $i<strlen($decoded['base']['rgba']); $i+=4) {
            foreach (['u','v'] as $key) if ($decoded[$key]['rgba'][$i+2] !== "\0" || $decoded[$key]['rgba'][$i+3] !== "\xff") throw new \InvalidArgumentException('UV16 maps must use RG coordinate, zero B and opaque A.');
            $mask = $decoded['coverage']['rgba'];
            if ($mask[$i] !== $mask[$i+1] || $mask[$i] !== $mask[$i+2] || $mask[$i+3] !== "\xff" || $decoded['lighting']['rgba'][$i+3] !== "\xff") throw new \InvalidArgumentException('Exact grayscale coverage and opaque lighting required.');
            $visible += ord($mask[$i]) > 0 ? 1 : 0;
        }
        if ($visible === 0) throw new \InvalidArgumentException('Nonempty reviewed printable coverage required.');
        return $decoded;
    }

    public static function compose(array $layers,string $artwork):string
    {
        $d = self::validateLayers($layers);
        $a = RasterArtworkCodec::decode($artwork);
        $rgba = $d['base']['rgba'];
        $denominator = self::SCALE*self::SCALE;
        $deadline = microtime(true)+8;
        for ($i=0; $i<strlen($rgba); $i+=4) {
            if ($i%4096 === 0 && microtime(true)>$deadline) throw new \InvalidArgumentException('Local projection time bound exceeded.');
            $coverage = ord($d['coverage']['rgba'][$i]);
            if ($coverage === 0) continue;
            $u = ord($d['u']['rgba'][$i])*256+ord($d['u']['rgba'][$i+1]);
            $v = ord($d['v']['rgba'][$i])*256+ord($d['v']['rgba'][$i+1]);
            $sx = $u*($a['width_px']-1); $sy = $v*($a['height_px']-1);
            $x = intdiv($sx,self::SCALE); $y = intdiv($sy,self::SCALE);
            $fx = $sx%self::SCALE; $fy = $sy%self::SCALE;
            $positions = [[$x,$y], [min($x+1,$a['width_px']-1),$y], [$x,min($y+1,$a['height_px']-1)], [min($x+1,$a['width_px']-1),min($y+1,$a['height_px']-1)]];
            $weights = [(self::SCALE-$fx)*(self::SCALE-$fy),$fx*(self::SCALE-$fy),(self::SCALE-$fx)*$fy,$fx*$fy];
            $alphaSum = 0; $colors = [0,0,0];
            foreach ($positions as $n=>$p) {
                $offset = ($p[1]*$a['width_px']+$p[0])*4;
                $alphaWeight = ord($a['rgba'][$offset+3])*$weights[$n];
                $alphaSum += $alphaWeight;
                for ($c=0;$c<3;$c++) $colors[$c] += ord($a['rgba'][$offset+$c])*$alphaWeight;
            }
            $alpha = intdiv(intdiv($alphaSum+intdiv($denominator,2),$denominator)*$coverage+127,255);
            $baseAlpha = ord($rgba[$i+3]); $combined = $alpha*255+$baseAlpha*(255-$alpha);
            for ($c=0;$c<3;$c++) {
                $color = $alphaSum===0 ? 0 : intdiv($colors[$c]+intdiv($alphaSum,2),$alphaSum);
                $lit = intdiv($color*ord($d['lighting']['rgba'][$i+$c])+127,255);
                $rgba[$i+$c] = chr($combined===0 ? 0 : intdiv($lit*$alpha*255+ord($rgba[$i+$c])*$baseAlpha*(255-$alpha)+intdiv($combined,2),$combined));
            }
            $rgba[$i+3] = chr(intdiv($combined+127,255));
        }
        return RasterArtworkCodec::encode($d['base']['width_px'],$d['base']['height_px'],$rgba);
    }
}
