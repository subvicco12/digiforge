<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Local raster/PDF adaptation of approved generated geometry; no shell or network. */
final class LocalArtworkAdapter
{
    public static function convert(string $svg,string $format,?int $dpi=null):array|WP_Error
    {
        if(!in_array($format,['svg','png','pdf'],true))return self::error('Unknown local artifact format.');
        $geometry=DeterministicPersonalizationRenderer::inspectSvg($svg);if($geometry instanceof WP_Error)return $geometry;
        if($format==='svg')return ['bytes'=>$svg,'mime'=>'image/svg+xml']+$geometry;
        try{
            $width=$geometry['width_px'];$height=$geometry['height_px'];RasterArtworkCodec::geometry($width,$height);
            $pixels=str_repeat("\0\0\0\0",$width*$height);$doc=new \DOMDocument();$doc->loadXML($svg,LIBXML_NONET);$painted=0;
            foreach($doc->documentElement->childNodes as $node){if(!($node instanceof \DOMElement))continue;
                if(!in_array($node->nodeName,['rect','image'],true))throw new \InvalidArgumentException('Raster text requires actual approved bitmap glyphs; system fonts are unsupported.');
                $bounds=[];foreach(['x','y','width','height']as $key){$value=$node->getAttribute($key);if(!ctype_digit($value))throw new \InvalidArgumentException('Exact integer raster placement required.');$bounds[$key]=(int)$value;}
                if($bounds['width']<1||$bounds['height']<1||$bounds['x']+$bounds['width']>$width||$bounds['y']+$bounds['height']>$height)throw new \InvalidArgumentException('Raster primitives must fit the approved panel.');
                $painted+=$bounds['width']*$bounds['height'];if($painted>RasterArtworkCodec::MAX_PIXELS*32)throw new \InvalidArgumentException('Raster painting exceeds work bound.');
                if($node->nodeName==='rect'){
                    $color=$node->getAttribute('fill');if(!preg_match('/^#[a-f0-9]{6}$/D',$color))throw new \InvalidArgumentException('Exact opaque raster color required.');$row=str_repeat(hex2bin(substr($color,1))."\xff",$bounds['width']);
                    if($bounds['x']===0&&$bounds['y']===0&&$bounds['width']===$width&&$bounds['height']===$height){$pixels=str_repeat($row,$height);}else{for($y=0;$y<$bounds['height'];$y++)for($x=0;$x<strlen($row);$x++)$pixels[(($bounds['y']+$y)*$width+$bounds['x'])*4+$x]=$row[$x];}
                }else{
                    $href=$node->getAttribute('href');if(!str_starts_with($href,'data:image/png;base64,')||$node->getAttribute('preserveAspectRatio')!=='xMidYMid meet')throw new \InvalidArgumentException('Local raster photos require supported PNG and exact contain placement.');
                    $bytes=base64_decode(substr($href,22),true);if(!is_string($bytes))throw new \InvalidArgumentException('Embedded photo encoding invalid.');$image=RasterArtworkCodec::decode($bytes);
                    $iw=$image['width_px'];$ih=$image['height_px'];$dw=$bounds['width'];$dh=$bounds['height'];if($dw*$ih>$dh*$iw)$dw=max(1,intdiv($dh*$iw,$ih));else $dh=max(1,intdiv($dw*$ih,$iw));$dx=$bounds['x']+intdiv($bounds['width']-$dw,2);$dy=$bounds['y']+intdiv($bounds['height']-$dh,2);
                    for($y=0;$y<$dh;$y++)for($x=0;$x<$dw;$x++){$source=(intdiv($y*$ih,$dh)*$iw+intdiv($x*$iw,$dw))*4;$target=(($dy+$y)*$width+$dx+$x)*4;$alpha=ord($image['rgba'][$source+3]);if(ord($pixels[$target+3])!==255)throw new \InvalidArgumentException('Explicit opaque background required for photo compositing.');for($c=0;$c<3;$c++)$pixels[$target+$c]=chr(intdiv(ord($image['rgba'][$source+$c])*$alpha+ord($pixels[$target+$c])*(255-$alpha)+127,255));$pixels[$target+3]="\xff";}
                }
            }
            if($format==='png')return ['bytes'=>RasterArtworkCodec::encode($width,$height,$pixels),'mime'=>'image/png','width_px'=>$width,'height_px'=>$height];
            if($dpi===null||$dpi<72||$dpi>1200)throw new \InvalidArgumentException('PDF requires explicit resolution in the exact approved template; no physical size is inferred.');$rgb='';for($i=0;$i<strlen($pixels);$i+=4){if(ord($pixels[$i+3])!==255)throw new \InvalidArgumentException('PDF requires an explicit opaque background.');$rgb.=substr($pixels,$i,3);}return ['bytes'=>RasterPdfAdapter::encode($width,$height,$rgb,$dpi),'mime'=>'application/pdf','width_px'=>$width,'height_px'=>$height];
        }catch(\InvalidArgumentException $e){return self::error($e->getMessage());}
    }
    private static function error(string $message):WP_Error{return new WP_Error('local_artwork_capability_unavailable',$message,['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
