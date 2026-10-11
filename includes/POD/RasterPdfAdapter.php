<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Self-contained deterministic raster PDF, not an arbitrary PDF parser or provider certificate. */
final class RasterPdfAdapter
{
    public static function encode(int $width,int $height,string $rgb,int $dpi=72):string
    {
        RasterArtworkCodec::geometry($width,$height);if(strlen($rgb)!==$width*$height*3||!function_exists('gzcompress')||$dpi<72||$dpi>1200)throw new \InvalidArgumentException('Complete bounded RGB pixels, explicit DPI and zlib required.');
        $pageWidth=number_format($width*72/$dpi,6,'.','');$pageHeight=number_format($height*72/$dpi,6,'.','');$image=gzcompress($rgb,9);$content='q '.$pageWidth.' 0 0 '.$pageHeight.' 0 0 cm /Im0 Do Q';
        $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$width.' '.$height.'] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>','<< /Type /XObject /Subtype /Image /Width '.$width.' /Height '.$height.' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length '.strlen($image).' >>' ."\nstream\n".$image."\nendstream",'<< /Length '.strlen($content).' >>' ."\nstream\n".$content."\nendstream"];
        $objects[2]=str_replace('/MediaBox [0 0 '.$width.' '.$height.']','/MediaBox [0 0 '.$pageWidth.' '.$pageHeight.']',$objects[2]);
        $pdf="%PDF-1.4\n% DigiForge bounded-raster-pdf-v1\n% dpi:".$dpi."\n";$offsets=[0];foreach($objects as $i=>$object){$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$object."\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";foreach(array_slice($offsets,1)as $offset)$pdf.=sprintf('%010d 00000 n ',$offset)."\n";return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
    public static function inspect(string $pdf):array
    {
        if(strlen($pdf)>16777216||!str_starts_with($pdf,"%PDF-1.4\n% DigiForge bounded-raster-pdf-v1\n")||!preg_match('~/Subtype /Image /Width ([1-9][0-9]*) /Height ([1-9][0-9]*) /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length ([0-9]+) >>\nstream\n~',$pdf,$m,PREG_OFFSET_CAPTURE))throw new \InvalidArgumentException('Only the exact bounded local PDF format is supported.');
        if(!preg_match('/\n% dpi:([0-9]+)\n/',$pdf,$resolution))throw new \InvalidArgumentException('PDF physical resolution required.');$dpi=(int)$resolution[1];
        $width=(int)$m[1][0];$height=(int)$m[2][0];RasterArtworkCodec::geometry($width,$height);$length=(int)$m[3][0];if($length<1||$length>16777216)throw new \InvalidArgumentException('PDF stream bound invalid.');$offset=$m[0][1]+strlen($m[0][0]);$rgb=@gzuncompress(substr($pdf,$offset,$length),$width*$height*3+1);
        if(!is_string($rgb)||strlen($rgb)!==$width*$height*3||!hash_equals(self::encode($width,$height,$rgb,$dpi),$pdf))throw new \InvalidArgumentException('PDF pixels, physical resolution, structure or exact canonical bytes disagree.');
        return ['width_px'=>$width,'height_px'=>$height,'dpi'=>$dpi,'pixel_sha256'=>hash('sha256',$rgb),'mime'=>'application/pdf','adapter_version'=>'bounded-raster-pdf-v1'];
    }
}
