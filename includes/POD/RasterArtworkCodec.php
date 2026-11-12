<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Bounded full PNG decoding. Unsupported encodings never fall back to header QA. */
final class RasterArtworkCodec
{
    public const MAX_PIXELS=1048576;
    public static function capabilities():array
    {
        return ['png_8bit_noninterlaced'=>function_exists('gzuncompress')&&function_exists('gzcompress'),'jpeg'=>function_exists('imagecreatefromstring'),'maximum_pixels'=>self::MAX_PIXELS,'external_execution_authorized'=>false];
    }
    public static function encode(int $width,int $height,string $rgba):string
    {
        self::geometry($width,$height);if(strlen($rgba)!==$width*$height*4||!self::capabilities()['png_8bit_noninterlaced'])throw new \InvalidArgumentException('Complete bounded RGBA pixels and zlib required.');
        $raw='';for($y=0;$y<$height;$y++)$raw.="\0".substr($rgba,$y*$width*4,$width*4);
        return "\x89PNG\r\n\x1a\n".self::chunk('IHDR',pack('NNCCCCC',$width,$height,8,6,0,0,0)).self::chunk('IDAT',gzcompress($raw,9)).self::chunk('IEND','');
    }
    public static function decode(string $bytes):array
    {
        if(strlen($bytes)>16777216||!str_starts_with($bytes,"\x89PNG\r\n\x1a\n")||!self::capabilities()['png_8bit_noninterlaced'])throw new \InvalidArgumentException('Supported bounded PNG and zlib required.');
        $offset=8;$header=null;$compressed='';$ended=false;$seenIdat=false;$chunks=0;
        while($offset<strlen($bytes)){
            if(++$chunks>1024||strlen($bytes)-$offset<12)throw new \InvalidArgumentException('Truncated PNG.');
            $length=unpack('N',substr($bytes,$offset,4))[1];$type=substr($bytes,$offset+4,4);
            if($length>16777216||$length>strlen($bytes)-$offset-12||!preg_match('/^[A-Za-z]{4}$/D',$type))throw new \InvalidArgumentException('Invalid PNG chunk.');
            $data=substr($bytes,$offset+8,$length);$crc=substr($bytes,$offset+8+$length,4);$offset+=12+$length;
            if(!hash_equals(hash('crc32b',$type.$data,true),$crc))throw new \InvalidArgumentException('PNG CRC mismatch.');
            if($header===null&&$type!=='IHDR')throw new \InvalidArgumentException('PNG header must be first.');
            if($type==='IHDR'){
                if($header!==null||$length!==13)throw new \InvalidArgumentException('Invalid PNG header.');
                $header=unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace',$data);self::geometry($header['width'],$header['height']);
                if($header['depth']!==8||!in_array($header['color'],[0,2,4,6],true)||$header['compression']!==0||$header['filter']!==0||$header['interlace']!==0)throw new \InvalidArgumentException('PNG encoding unsupported; no certification inferred.');
            }elseif($type==='IDAT'){
                $seenIdat=true;$compressed.=$data;
            }elseif($type==='IEND'){
                if($length!==0||!$seenIdat||$offset!==strlen($bytes))throw new \InvalidArgumentException('PNG end invalid.');$ended=true;break;
            }else{
                // Color profiles, transparency and metadata require a separate reviewed decoder.
                throw new \InvalidArgumentException('PNG ancillary or critical feature unsupported.');
            }
        }
        if(!$ended||!is_array($header))throw new \InvalidArgumentException('Incomplete PNG.');
        $channels=[0=>1,2=>3,4=>2,6=>4][$header['color']];$stride=$header['width']*$channels;$expected=($stride+1)*$header['height'];
        $raw=@gzuncompress($compressed,$expected+1);if(!is_string($raw)||strlen($raw)!==$expected)throw new \InvalidArgumentException('PNG pixel stream invalid or exceeds expansion bound.');
        $rgba='';$previous=array_fill(0,$stride,0);$cursor=0;
        for($y=0;$y<$header['height'];$y++){
            $filter=ord($raw[$cursor++]);if($filter>4)throw new \InvalidArgumentException('PNG row filter invalid.');$row=[];
            for($x=0;$x<$stride;$x++){
                $left=$x>=$channels?$row[$x-$channels]:0;$up=$previous[$x];$upperLeft=$x>=$channels?$previous[$x-$channels]:0;
                $prediction=match($filter){0=>0,1=>$left,2=>$up,3=>intdiv($left+$up,2),4=>self::paeth($left,$up,$upperLeft)};
                $row[$x]=(ord($raw[$cursor++])+$prediction)&255;
            }
            for($x=0;$x<$stride;$x+=$channels){$r=$row[$x];$g=$channels>=3?$row[$x+1]:$r;$b=$channels>=3?$row[$x+2]:$r;$a=match($channels){2=>$row[$x+1],4=>$row[$x+3],default=>255};$rgba.=chr($r).chr($g).chr($b).chr($a);}$previous=$row;
        }
        return ['width_px'=>$header['width'],'height_px'=>$header['height'],'rgba'=>$rgba,'pixel_sha256'=>hash('sha256',$rgba),'decoder_version'=>'bounded-png-v1'];
    }
    public static function geometry(int $width,int $height):void
    {
        if($width<1||$height<1||$width>10000||$height>10000||$width*$height>self::MAX_PIXELS)throw new \InvalidArgumentException('Raster geometry exceeds the bounded local capability.');
    }
    private static function chunk(string $type,string $data):string{return pack('N',strlen($data)).$type.$data.hash('crc32b',$type.$data,true);}
    private static function paeth(int $a,int $b,int $c):int{$p=$a+$b-$c;$pa=abs($p-$a);$pb=abs($p-$b);$pc=abs($p-$c);return $pa<=$pb&&$pa<=$pc?$a:($pb<=$pc?$b:$c);}
}
