<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Binds a validated local image or digital-file asset to an already controlled Etsy transport. */
final class EtsyMultipartTransportBinder
{
    /** @return array<string,mixed>|WP_Error */
    public static function bind(array $prepared,array $multipart): array|WP_Error
    {
        if(($prepared['state']??'')!=='ETSY_CONTROLLED_TRANSPORT_PREPARED'||($prepared['network_request_permitted']??null)!==false)return self::error('transport','Controlled pre-network transport is required.');
        $request=$prepared['request_plan']??null;
        if(!is_array($request)||($request['method']??'')!=='POST')return self::error('target','Multipart assets require an approved Etsy POST transport.');
        $state=(string)($multipart['state']??''); $endpoint=(string)($request['endpoint']??'');
        $image=$state==='ETSY_MULTIPART_IMAGE_PREPARED'&&preg_match('#^/application/shops/[1-9][0-9]*/listings/[1-9][0-9]*/images$#',$endpoint);
        $file=$state==='ETSY_MULTIPART_FILE_PREPARED'&&preg_match('#^/application/shops/[1-9][0-9]*/listings/[1-9][0-9]*/files$#',$endpoint);
        if(!$image&&!$file)return self::error('multipart','Prepared multipart metadata must match an approved Etsy image or digital-file endpoint.');
        if(($multipart['method']??'')!==($request['method']??'')||!hash_equals((string)($request['endpoint']??''),(string)($multipart['endpoint']??'')))return self::error('resource','Multipart image plan must target the exact authorized Etsy listing resource.');
        if((int)($multipart['size']??0)<1||($image&&(int)$multipart['size']>10485760))return self::error('size','Multipart asset size is invalid.');
        $payload=is_array($request['payload']??null)?$request['payload']:[];
        $expected=$image?[
            'image_sha256'=>strtolower((string)($multipart['sha256']??'')),
            'image_size'=>(int)($multipart['size']??0),
            'image_mime'=>(string)($multipart['mime_type']??''),
            'rank'=>(int)($multipart['rank']??0),
        ]:[
            'file_sha256'=>strtolower((string)($multipart['sha256']??'')),
            'file_size'=>(int)($multipart['size']??0),
            'name'=>(string)($multipart['filename']??''),
            'rank'=>(int)($multipart['rank']??0),
        ];
        foreach($expected as $key=>$value){
            $actual=$payload[$key]??null;
            if(is_string($value)){if(!is_string($actual)||!hash_equals($value,$actual))return self::error('binding','Multipart asset metadata does not match the authorized payload.');}
            elseif((int)$actual!==$value)return self::error('binding','Multipart asset metadata does not match the authorized payload.');
        }
        $prepared['request_plan']['multipart']=$multipart;
        $prepared['request_plan']['payload']=[];
        $prepared['multipart_bound']=true;
        $prepared['network_request_permitted']=false;
        $prepared['external_execution_performed']=false;
        return $prepared;
    }
    private static function error(string $c,string $m): WP_Error{return new WP_Error('digiforge_etsy_multipart_bind_'.$c,$m,['status'=>409]);}
}
