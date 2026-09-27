<?php
declare(strict_types=1);
namespace DigiForge\Integrations;
use DigiForge\Database\Tables;
use WP_Error;
/** Single-use credential envelope restricted to audited Etsy read-only requests. */
final class EtsyReadOnlyCredentialEnvelope
{
    private bool $used=false;
    private function __construct(private string $apiKey,private string $access){}
    public static function forIntegration(int $integrationId): self|WP_Error
    {
        if($integrationId<1)return self::error('integration');
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT secret_name,ciphertext FROM '.Tables::integration_secrets().' WHERE integration_id=%d AND secret_name IN (%s,%s,%s)',$integrationId,'access_token','keystring','shared_secret'),ARRAY_A);
        $m=[];
        foreach(is_array($rows)?$rows:[] as $row){$n=(string)($row['secret_name']??'');if(!in_array($n,['access_token','keystring','shared_secret'],true)||empty($row['ciphertext']))continue;try{$m[$n]=CredentialVault::decrypt((string)$row['ciphertext'],Repository::secretContext($integrationId,$n));}catch(\Throwable $e){$m=[];return self::error('decrypt');}}
        if(($m['access_token']??'')===''||($m['keystring']??'')===''||($m['shared_secret']??'')==='')return self::error('missing');
        $self=new self($m['keystring'].':'.$m['shared_secret'],$m['access_token']);$m=[];return $self;
    }
    public function consume(callable $callback): mixed
    {
        if($this->used)return self::error('used');$this->used=true;
        try{return $callback($this->apiKey,$this->access);}finally{$this->apiKey='';$this->access='';}
    }
    private static function error(string $code): WP_Error{return new WP_Error('digiforge_etsy_read_credentials_'.$code,'Etsy read-only credential access is unavailable.',['status'=>409]);}
}
