<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
/** Fixed-origin WordPress transport; only internal services compose it, never enabled by default. */
final class DriveWordPressGoogleHttp implements DriveGoogleHttp
{
    public function __construct(private DriveGoogleGate $gate){}
    public function exchange(#[\SensitiveParameter] array $binding,string $url,#[\SensitiveParameter] array $args):array
    {
        if(!self::valid($url,$args)||!$this->gate->allows($binding))throw new \RuntimeException('Google dispatch blocked.');
        // Gate runs immediately before WordPress takes custody of the transient bearer/body.
        $args['cookies']=[];$args['blocking']=true;$args['stream']=false;
        $response=wp_remote_request($url,$args);
        if(is_wp_error($response))return ['status'=>0,'headers'=>[],'body'=>''];
        $body=wp_remote_retrieve_body($response);$headers=wp_remote_retrieve_headers($response);
        $headers=is_array($headers)?$headers:(method_exists($headers,'getAll')?$headers->getAll():[]);
        if(!is_string($body)||strlen($body)>$args['limit_response_size'])return ['status'=>0,'headers'=>[],'body'=>''];
        return ['status'=>(int)wp_remote_retrieve_response_code($response),'headers'=>array_intersect_key($headers,array_flip(['content-type','content-length','content-encoding'])),'body'=>$body];
    }
    /** Also used before any secret is handed to the WordPress HTTP client. */
    public static function valid(string $url,#[\SensitiveParameter] array $args):bool
    {
        if(strlen($url)>8192||($args['sslverify']??null)!==true||($args['redirection']??null)!==0||($args['reject_unsafe_urls']??null)!==true
            ||($args['timeout']??null)!==20||!is_int($args['limit_response_size']??null)||$args['limit_response_size']<1||$args['limit_response_size']>10000001
            ||!is_array($args['headers']??null)||array_diff(array_keys($args),['method','timeout','redirection','sslverify','reject_unsafe_urls','limit_response_size','headers','body'])!==[])return false;
        foreach($args['headers'] as $k=>$v){if(!in_array($k,['Accept','Accept-Encoding','Authorization','Content-Type','Content-Length'],true)||!is_string($v)||strlen($v)>8192||preg_match('/[\r\n\x00]/',$v))return false;}
        if(($args['headers']['Accept-Encoding']??null)!=='identity')return false;
        $u=parse_url($url);
        if(!is_array($u)||($u['scheme']??null)!=='https'||array_diff(array_keys($u),['scheme','host','path','query'])!==[])return false;
        $method=$args['method']??'';$path=$u['path']??'';$host=$u['host']??'';
        if($host==='oauth2.googleapis.com'){
            return $method==='POST'&&in_array($path,['/token','/revoke'],true)&&!isset($u['query'])
                &&($args['headers']['Content-Type']??null)==='application/x-www-form-urlencoded'&&!isset($args['headers']['Authorization'])
                &&is_string($args['body']??null)&&strlen($args['body'])<=20000;
        }
        if($host!=='www.googleapis.com'||!is_string($args['headers']['Authorization']??null)
            ||preg_match('/^Bearer [A-Za-z0-9._~+\/-]{1,4096}=*$/D',$args['headers']['Authorization'])!==1)return false;
        if($method==='GET')return !isset($args['body'])&&($path==='/drive/v3/about'||preg_match('#^/drive/v3/files(?:/[A-Za-z0-9_-]{1,160}(?:/export)?)?$#D',$path)===1);
        return $method==='POST'&&$path==='/upload/drive/v3/files'&&is_string($args['body']??null)
            &&strlen($args['body'])<=DriveTransferRequest::LIMIT+16384
            &&preg_match('/^multipart\/related; boundary=df_[a-f0-9]{64}$/D',$args['headers']['Content-Type']??'')===1;
    }
}
