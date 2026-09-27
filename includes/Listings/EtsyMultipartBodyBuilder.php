<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** RFC 7578-style byte-exact multipart body builder for Etsy media uploads. */
final class EtsyMultipartBodyBuilder {
    /** @return array{content_type:string,content_length:int,body:string}|WP_Error */
    public static function build(array $multipart,string $bytes,string $boundary,bool $includeName): array|WP_Error {
        $field=(string)($multipart['field']??''); $filename=(string)($multipart['filename']??'');
        $mime=(string)($multipart['mime_type']??''); $rank=(int)($multipart['rank']??0);
        if(!in_array($field,['file','image'],true)||$filename===''||$mime===''||$rank<1||$bytes===''||!preg_match('/^[A-Za-z0-9._-]{16,100}$/',$boundary)) return new WP_Error('digiforge_etsy_multipart_body','Invalid multipart body inputs.',['status'=>409]);
        $safe=str_replace(["\r","\n",'"'],'',$filename);
        $body='';
        if($includeName) $body.=self::text($boundary,'name',$safe);
        $body.=self::text($boundary,'rank',(string)$rank);
        $body.='--'.$boundary."\r\n".'Content-Disposition: form-data; name="'.$field.'"; filename="'.$safe.'"'."\r\n".'Content-Type: '.$mime."\r\n\r\n".$bytes."\r\n";
        $body.='--'.$boundary."--\r\n";
        return ['content_type'=>'multipart/form-data; boundary="'.$boundary.'"','content_length'=>strlen($body),'body'=>$body];
    }
    private static function text(string $boundary,string $name,string $value): string {
        return '--'.$boundary."\r\n".'Content-Disposition: form-data; name="'.$name.'"'."\r\n\r\n".$value."\r\n";
    }
}
