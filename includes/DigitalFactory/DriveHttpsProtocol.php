<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;

/** Fixed-origin Drive v3 codec. Contains no sender, credential access or execution grant. */
final class DriveHttpsProtocol
{
    /** Encode only an exact existing source-bound plan; returned arguments contain no secrets. */
    public static function encode(#[\SensitiveParameter] array $request,#[\SensitiveParameter] array $plan,#[\SensitiveParameter] array $completed,#[\SensitiveParameter] string $sourceBytes):array
    {
        $stage=$request['stage']??'';
        if(!is_string($stage))throw new \InvalidArgumentException('Invalid Drive request.');
        $canonical=str_starts_with($stage,'reconcile_')
            ?DriveTransferRequest::reconciliation(substr($stage,10),$plan)
            :DriveTransferRequest::build($stage,$plan,$completed);
        if(self::canonical($request)!==self::canonical($canonical))throw new \InvalidArgumentException('Invalid Drive request.');
        $args=['method'=>$canonical['method'],'timeout'=>20,'redirection'=>0,'sslverify'=>true,
            'reject_unsafe_urls'=>true,'limit_response_size'=>$canonical['max_response_bytes']+1,
            'headers'=>['Accept'=>in_array($stage,['original_readback','pdf_export'],true)?'*/*':'application/json','Accept-Encoding'=>'identity']];
        $boundary='';
        if($canonical['method']==='POST'){
            if($sourceBytes===''||strlen($sourceBytes)>DriveTransferRequest::LIMIT||!hash_equals($plan['source_sha256'],hash('sha256',$sourceBytes)))throw new \InvalidArgumentException('Invalid Drive source.');
            $metadata=json_encode($canonical['metadata'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            // Stable framing preserves retry identity; collisions deny instead of corrupting bytes.
            $boundary='df_'.hash('sha256',$plan['id'].':'.$stage);
            if(str_contains($sourceBytes,$boundary)||str_contains($metadata,$boundary))throw new \InvalidArgumentException('Invalid Drive framing.');
            $args['body']='--'.$boundary."\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".$metadata."\r\n--".$boundary."\r\nContent-Type: ".DriveTransferRequest::DOCX."\r\n\r\n".$sourceBytes."\r\n--".$boundary."--\r\n";
            $args['headers']['Content-Type']='multipart/related; boundary='.$boundary;
            $args['headers']['Content-Length']=(string)strlen($args['body']);
        }
        return ['url'=>'https://www.googleapis.com'.$canonical['path'].'?'.http_build_query($canonical['query'],'','&',PHP_QUERY_RFC3986),
            'args'=>$args,'boundary'=>$boundary,'network_request_permitted'=>false];
    }

    /** Decode bounded HTTP observations. Never return provider error bodies, headers or URLs. */
    public static function decode(#[\SensitiveParameter] array $request,int $status,#[\SensitiveParameter] array $headers,#[\SensitiveParameter] string $body):array
    {
        if($status!==200)return ['status'=>in_array($status,[301,302,303,307,308,400,401,403,404,409,429,500,502,503,504],true)?$status:0];
        $limit=$request['max_response_bytes']??0;$stage=$request['stage']??'';
        if(!is_int($limit)||$limit<1||$limit>10000000||strlen($body)>$limit||$body==='')return ['status'=>0];
        $headers=array_change_key_case($headers,CASE_LOWER);
        if(isset($headers['content-length'])&&(!is_scalar($headers['content-length'])||preg_match('/^[0-9]{1,10}$/D',(string)$headers['content-length'])!==1||(int)$headers['content-length']!==strlen($body)))return ['status'=>0];
        $type=$headers['content-type']??'';
        if(!is_string($type)||isset($headers['content-encoding'])&&$headers['content-encoding']!=='identity')return ['status'=>0];
        $type=strtolower(trim(explode(';',$type,2)[0]));
        if(in_array($stage,['original_readback','pdf_export'],true)){
            if($type!==($stage==='pdf_export'?'application/pdf':DriveTransferRequest::DOCX))return ['status'=>0];
            return ['status'=>200,'bytes'=>$body];
        }
        if($type!=='application/json')return ['status'=>0];
        try{$object=json_decode($body,false,32,JSON_THROW_ON_ERROR);}catch(\Throwable){return ['status'=>0];}
        if(!$object instanceof \stdClass)return ['status'=>0];
        $data=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        if(str_starts_with((string)$stage,'reconcile_')){
            if(!isset($data['files'])||!is_array($data['files'])||!array_is_list($data['files'])||count($data['files'])>2||isset($data['nextPageToken']))return ['status'=>0];
            $files=[];foreach($data['files'] as $file){$safe=self::metadata($file);if($safe===null)return ['status'=>0];$files[]=$safe;}
            return ['status'=>200,'files'=>$files];
        }
        // Detailed identity/MIME/parent/version validation remains in the existing orchestrator.
        $safe=self::metadata($data);return $safe===null?['status'=>0]:['status'=>200,'metadata'=>$safe];
    }
    private static function metadata(#[\SensitiveParameter] mixed $value):?array
    {
        if(!is_array($value)||isset($value['error'])||!DriveTransferRequest::validId($value['id']??null))return null;
        if(isset($value['mimeType'])&&!in_array($value['mimeType'],[DriveTransferRequest::DOCX,DriveTransferRequest::DOC,'application/vnd.google-apps.folder'],true))return null;
        if(isset($value['version'])&&(!is_string($value['version'])||preg_match('/^[1-9][0-9]{0,19}$/D',$value['version'])!==1))return null;
        if(isset($value['trashed'])&&!is_bool($value['trashed']))return null;
        if(isset($value['parents'])&&(!is_array($value['parents'])||$value['parents']!==[DriveTransferRequest::FOLDER]))return null;
        $safe=array_intersect_key($value,array_flip(['id','mimeType','parents','trashed','version']));
        if(isset($value['capabilities'])&&is_array($value['capabilities'])){
            $safe['capabilities']=array_intersect_key($value['capabilities'],array_flip(['canDownload','canAddChildren']));
            foreach($safe['capabilities'] as $cap)if(!is_bool($cap))return null;
        }
        if(isset($value['appProperties'])&&is_array($value['appProperties'])){
            if(array_diff(array_keys($value['appProperties']),['digiforge_transfer','digiforge_role','source_sha256'])!==[])return null;
            foreach(['digiforge_transfer','source_sha256'] as $key)if(isset($value['appProperties'][$key])&&(!is_string($value['appProperties'][$key])||preg_match('/^[a-f0-9]{64}$/D',$value['appProperties'][$key])!==1))return null;
            if(isset($value['appProperties']['digiforge_role'])&&!in_array($value['appProperties']['digiforge_role'],['original_upload','native_conversion'],true))return null;
            $safe['appProperties']=$value['appProperties'];
        }
        return $safe;
    }
    private static function canonical(#[\SensitiveParameter] array $value):string
    {
        if(!array_is_list($value))ksort($value);
        foreach($value as &$item)if(is_array($item))$item=self::canonical($item);
        unset($item);return json_encode($value,JSON_THROW_ON_ERROR);
    }
}
