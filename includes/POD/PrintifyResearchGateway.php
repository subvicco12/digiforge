<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Adapter to the existing governed pipeline. No HTTP, credentials, approvals or fallback client. */
final class PrintifyResearchGateway implements PrintifyResearchReader
{
    public static function readiness(): array
    {
        return PrintifyResearchDependencies::inspect();
    }
    public function describe(string $grant): array|WP_Error
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$grant))return self::error('identity',400);
        if(self::readiness()['pipeline_state']!=='GOVERNED_PIPELINE_PRESENT')return self::error('pipeline_unavailable',503);
        try{
            if(!PrintifyCatalogReadRepository::reader())return self::error('account',403);
            $repo=new PrintifyCatalogReadRepository();$shop=$repo->targetShopForGrant($grant);if(is_wp_error($shop))return $shop;if($shop!=='personalized_pod')return self::error('shop',403);
            foreach(['manage_digiforge_connections','manage_digiforge_pod'] as $cap){$access=\DigiForge\Security\ShopAccess::authorize('personalized_pod',$cap);if(is_wp_error($access))return $access;}
            global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s LIMIT 1','df_catalog_read_grant_'.$grant));
            if($wpdb->last_error!==''||!is_string($raw)||strlen($raw)>8192)return self::error('grant_unavailable',503);
            $record=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$seal=$record['sha256']??null;unset($record['sha256']);
            if($seal!==$grant||!hash_equals($grant,hash('sha256',self::canonical($record))))return self::error('grant_integrity',503);
            $g=PrintifyCatalogReadContract::normalize($record['grant'],time());
            if($g['account_id']!==get_current_user_id()||$g['product_program']!=='PERSONALIZED_POD'||!user_can($record['approved_by'],'manage_options'))return self::error('account',403);
            // This metadata is descriptive only; research()/evidence() enforce active grant,
            // exceptions, ownership, reservations, rate budget and audit at each boundary.
            return ['grant_sha256'=>$grant,'requests'=>$g['requests'],'max_requests'=>$g['max_requests'],'integration_id'=>$g['integration_id'],'account_id'=>$g['account_id'],'expires_at'=>$g['expires_at'],'shop_key'=>'personalized_pod','binding'=>$record['binding'],'external_execution_authorized'=>false];
        }catch(\Throwable){return self::error('grant_unavailable',503);}
    }
    public function collect(string $grant,int $index,string $key): array|WP_Error
    {
        $scope=$this->describe($grant);if(is_wp_error($scope))return $scope;
        if(!isset($scope['requests'][$index]))return self::error('request_index',400);
        try{$receipt=(new PrintifyCatalogReadRepository())->research($grant,$index,$key,0);if(is_wp_error($receipt))return $receipt;if(($receipt['request_sha256']??null)!==hash('sha256',$key))return self::error('receipt_attempt',503);return $this->verified($grant,$receipt);}
        catch(\Throwable){return self::error('collection_unknown',503);}
    }
    public function evidence(string $grant,string $request): array|WP_Error
    {
        $scope=$this->describe($grant);if(is_wp_error($scope))return $scope;
        try{$receipt=(new PrintifyCatalogReadRepository())->evidence($grant,$request);return is_wp_error($receipt)?$receipt:$this->verified($grant,$receipt);}
        catch(\Throwable){return self::error('evidence_unavailable',503);}
    }
    private function verified(string $grant,array $receipt): array|WP_Error
    {
        // Re-fetch the durable, audited receipt; batch attempts disable cache reuse so recovery has an exact request key.
        $read=(new PrintifyCatalogReadRepository())->evidence($grant,(string)($receipt['request_sha256']??''));if(is_wp_error($read))return $read;
        $scope=$this->describe($grant);if(is_wp_error($scope))return $scope;
        if(($read['integration_id']??null)!==$scope['integration_id']||($read['account_id']??null)!==$scope['account_id']||($read['scope']['product_program']??null)!=='PERSONALIZED_POD'||($read['grant_sha256']??null)!==$grant)return self::error('receipt_scope',403);
        PrintifyResearchMapper::map($read);return $read;
    }
    public static function canonical(array $input): string
    {
        $sort=static function(array $a)use(&$sort):array{foreach($a as &$v)if(is_array($v))$v=$sort($v);unset($v);if(!array_is_list($a))ksort($a);return $a;};
        return json_encode($sort($input),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
    public static function error(string $code,int $status=409): WP_Error {return new WP_Error('pod_research_'.$code,'Governed Shop 2 catalog research unavailable. No automatic retry or external authority.',['status'=>$status,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
