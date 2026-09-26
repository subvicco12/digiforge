<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Read-only Gelato Product API catalog transport. */
final class GelatoCatalogClient
{
    private const BASE='https://product.gelatoapis.com/v3/';
    private const MAX_ATTEMPTS=3;
    private const MAX_RETRY_AFTER_SECONDS=60;
    private $transport;
    private $sleep;

    public function __construct(?callable $transport=null,?callable $sleep=null)
    {
        $this->transport=$transport??static fn(string $method,string $url,array $args):array|WP_Error=>$method==='GET'?wp_remote_get($url,$args):wp_remote_post($url,$args);
        $this->sleep=$sleep??static function(int $microseconds):void{usleep($microseconds);};
    }

    public function catalogs(string $apiKey):array|WP_Error{return $this->request('GET',$apiKey,self::BASE.'catalogs');}
    public function catalog(string $apiKey,string $catalogUid):array|WP_Error
    {$catalogUid=$this->uid($catalogUid);return $this->request('GET',$apiKey,self::BASE.'catalogs/'.rawurlencode($catalogUid));}
    public function products(string $apiKey,string $catalogUid,int $limit=100,int $offset=0,array $attributeFilters=[]):array|WP_Error
    {
        $catalogUid=$this->uid($catalogUid);
        if($limit<1||$limit>100||$offset<0)return new WP_Error('digiforge_gelato_pagination','Gelato catalog pagination is outside the approved bounds.',['status'=>400]);
        if(!is_array($attributeFilters))return new WP_Error('digiforge_gelato_filters','Gelato catalog filters must be an array.',['status'=>400]);
        return $this->request('POST',$apiKey,self::BASE.'catalogs/'.rawurlencode($catalogUid).'/products:search',['body'=>wp_json_encode(['attributeFilters'=>$attributeFilters,'limit'=>$limit,'offset'=>$offset],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    }
    private function uid(string $value):string
    {
        $value=trim($value);
        if($value===''||strlen($value)>191||preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/',$value)!==1)throw new \InvalidArgumentException('Gelato catalog identifiers must be non-empty safe tokens.');
        return $value;
    }
    private function request(string $method,string $apiKey,string $url,array $extra=[]):array|WP_Error
    {
        $apiKey=trim($apiKey);
        if($apiKey==='')return new WP_Error('digiforge_gelato_credentials','Gelato API key is required.',['status'=>409]);
        if(!$this->approvedEndpoint($url))return new WP_Error('digiforge_gelato_endpoint','Gelato endpoint is outside the approved catalog boundary.',['status'=>400]);
        $args=array_merge(['timeout'=>30,'redirection'=>0,'reject_unsafe_urls'=>true,'sslverify'=>true,'headers'=>['X-API-KEY'=>$apiKey,'Accept'=>'application/json','Content-Type'=>'application/json','User-Agent'=>'DigiForge/GelatoCatalogSync']],$extra);
        for($attempt=1;$attempt<=self::MAX_ATTEMPTS;$attempt++){
            $response=($this->transport)($method,$url,$args);
            if(is_wp_error($response))return new WP_Error('digiforge_gelato_transport','Gelato catalog transport failed.',['status'=>502]);
            $status=(int)wp_remote_retrieve_response_code($response);
            if($status===429){
                if($attempt===self::MAX_ATTEMPTS)return new WP_Error('digiforge_gelato_rate_limit','Gelato catalog rate limit retry budget exhausted.',['status'=>429]);
                $retry=$this->retryAfterSeconds((string)wp_remote_retrieve_header($response,'retry-after'));
                ($this->sleep)(($retry!==null?$retry*1000000:(2**($attempt-1))*500000));continue;
            }
            if($status<200||$status>=300)return new WP_Error('digiforge_gelato_http','Gelato catalog request failed.',['status'=>$status?:502]);
            $body=(string)wp_remote_retrieve_body($response);
            try{$decoded=json_decode($body,true,512,JSON_THROW_ON_ERROR);}catch(\JsonException){return new WP_Error('digiforge_gelato_json','Gelato returned invalid JSON.',['status'=>502]);}
            if(!is_array($decoded))return new WP_Error('digiforge_gelato_shape','Gelato returned an unsupported response shape.',['status'=>502]);
            return $decoded;
        }
        return new WP_Error('digiforge_gelato_rate_limit','Gelato catalog rate limit retry budget exhausted.',['status'=>429]);
    }
    private function approvedEndpoint(string $url):bool
    {
        $parts=parse_url($url);
        if(!is_array($parts)||($parts['scheme']??'')!=='https'||($parts['host']??'')!=='product.gelatoapis.com'||isset($parts['user'],$parts['pass'],$parts['fragment']))return false;
        return preg_match('#^/v3/catalogs(?:/[A-Za-z0-9][A-Za-z0-9._-]*(?:/products:search)?)?$#',(string)($parts['path']??''))===1;
    }
    private function retryAfterSeconds(string $value):?int
    {
        $value=trim($value);if($value==='')return null;if(ctype_digit($value))return min((int)$value,self::MAX_RETRY_AFTER_SECONDS);
        $at=strtotime($value);if($at===false)return null;return min(max(0,$at-time()),self::MAX_RETRY_AFTER_SECONDS);
    }
}