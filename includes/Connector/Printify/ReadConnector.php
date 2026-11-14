<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
use DigiForge\POD\{PrintifyCatalogClient,PrintifyConnectorReadContract};
/** All provider execution stays inside the trusted DigiForge host/client boundary. */
final class ReadConnector implements ConnectorInterface {
 public function __construct(private HostInterface $host,private PrintifyCatalogClient $client=new PrintifyCatalogClient()){}
 public function version():string{return '1.0.0';}
 public function read(ExternalOperationIntent $intent):ProviderResult|\WP_Error {
  $attempted=false;$started=microtime(true);
  $receipt=['intent_id'=>$intent->intentId,'operation'=>$intent->operation,'shop_key'=>$intent->shopKey,'integration_id'=>$intent->integrationId,'outcome'=>'STARTED','status'=>0,'request_attempted'=>false];
  try{
   $binding=$this->host->authorize($intent);if(is_wp_error($binding))return $binding;
   if(!$this->matches($intent,$binding))return self::error('scope');
   $request=PrintifyConnectorReadContract::prepare($intent->operation,$binding->providerShopId,$intent->input);
   $reserved=$this->host->reserve($intent,$binding);if($reserved!==true)return is_wp_error($reserved)?$reserved:self::error('budget');
   if(!$this->host->record($receipt))return self::error('audit');
   $result=$this->host->consume($binding->credential,function(string $token)use($intent,$binding,$request,&$attempted){
    $check=function()use($intent,$binding):bool{$current=$this->host->authorize($intent);return $current instanceof ShopBinding&&$this->matches($intent,$current)&&hash_equals($binding->fingerprint(),$current->fingerprint());};
    if(!$check())return self::error('revoked');
    $raw=$this->client->connectorRead($token,$request,function(string $phase)use($check,&$attempted):bool{if(!$check())return false;if($phase==='before')$attempted=true;return true;});
    if(is_wp_error($raw))return $raw;
    $data=ReadProjection::project($intent,$binding,$raw);
    // Enforce secret rejection after JSON decoding too (escaped reflected tokens).
    if(PrintifyConnectorReadContract::containsProtectedMaterial($data,$token))return self::error('protected_data');
    if(!$check())return self::error('revoked');
    return $data;
   });
   $receipt['request_sha256']=$request['fingerprint'];$receipt['binding_sha256']=$binding->fingerprint();$receipt['observed_at']=gmdate('Y-m-d\TH:i:s\Z');if(is_array($result))$receipt['response_sha256']=hash('sha256',json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
   $receipt['request_attempted']=$attempted;$receipt['outcome']=is_wp_error($result)?'UNKNOWN':'OBSERVED';$receipt['status']=is_wp_error($result)?self::providerFailure($result)['status']:200;$receipt['duration_ms']=max(0,(int)((microtime(true)-$started)*1000));
   if(!$this->host->record($receipt))return self::error('audit');
   if(is_wp_error($result))return self::error('read_failed',self::providerFailure($result));
   if(!is_array($result))return self::error('response');
   $last=$this->host->authorize($intent);if(!$last instanceof ShopBinding||!$this->matches($intent,$last)||!hash_equals($binding->fingerprint(),$last->fingerprint()))return self::error('revoked');
   return new ProviderResult($result,$receipt);
  }catch(\Throwable){$receipt['outcome']=$attempted?'UNKNOWN':'DENIED';$receipt['request_attempted']=$attempted;try{$this->host->record($receipt);}catch(\Throwable){}return self::error('unavailable');}
 }
 private function matches(ExternalOperationIntent $i,ShopBinding $b):bool{return $b->shopKey===$i->shopKey&&$b->integrationId===$i->integrationId&&$b->expiresAt>time();}
 private static function providerFailure(\WP_Error $error):array {
  $raw=method_exists($error,'get_error_data')?$error->get_error_data():($error->data??[]);$raw=is_array($raw)?$raw:[];$status=$raw['status']??502;if(!is_int($status)||$status<400||$status>599)$status=502;
  $out=['status'=>$status,'category'=>match(true){$status===401||$status===403=>'AUTHORIZATION',$status===404=>'NOT_FOUND',$status===429=>'RATE_LIMIT',$status>=500=>'PROVIDER_OR_RESPONSE_UNAVAILABLE',default=>'ADMISSION_OR_RESPONSE_DENIED'}];
  if(isset($raw['retry_after_seconds'])&&is_int($raw['retry_after_seconds'])&&$raw['retry_after_seconds']>=0&&$raw['retry_after_seconds']<=60)$out['retry_after_seconds']=$raw['retry_after_seconds'];return $out;
 }
 private static function error(string $reason,array $details=[]):\WP_Error{return new \WP_Error('printify_connector_'.$reason,'Governed Printify read is unavailable.',array_replace(['status'=>409,'retry_permitted'=>false,'mutation_authorized'=>false],$details));}
}
