<?php
declare(strict_types=1);
namespace DigiForge\REST;

use DigiForge\POD\PrintifyCatalogReadRepository;
use DigiForge\POD\PrintifyResearchBatch;
use DigiForge\POD\PrintifyResearchGateway;
use WP_REST_Request;

/** API-lane admission for the imported catalog routes only; no cumulative global guard. */
final class PrintifyCatalogShopAdmission
{
    public function register(): void {add_filter('rest_pre_dispatch',[$this,'admit'],10,3);}
    public function admit(mixed $result,mixed $server,WP_REST_Request $request): mixed
    {
        $route=strtolower(rtrim($request->get_route(),"/\r\n"));$base='/digiforge/v1/printify/catalog-read';
        $create=$route===$base.'/grants'&&$request->get_method()==='POST';
        $matched=preg_match('@^'.preg_quote($base,'@').'/([a-f0-9]{64})(?:(/safety-exception(?:/revoke)?|/revoke)|(/evidence/[a-f0-9]{64}))?$@D',$route,$parts)===1;
        $read=$matched&&in_array($request->get_method(),['GET','HEAD'],true)&&empty($parts[2]);
        $write=$matched&&$request->get_method()==='POST'&&!empty($parts[2]);
        if($result!==null||(!$create&&!$read&&!$write))return $result;
        if(!PrintifyResearchBatch::allowed()||!wp_verify_nonce((string)$request->get_header('X-WP-Nonce'),'wp_rest'))return PrintifyResearchGateway::error('catalog_scope',403);
        if(strlen((string)$request->get_body())>8192)return PrintifyResearchGateway::error('body_size',413);
        try{
            $repository=new PrintifyCatalogReadRepository();
            if($create){$body=$request->get_json_params();if(!is_array($body))return PrintifyResearchGateway::error('catalog_scope',403);$shop=$repository->targetShopForInput($body);}
            else $shop=$repository->targetShopForGrant($parts[1]);
            if($shop!=='personalized_pod')return PrintifyResearchGateway::error('catalog_scope',403);
        }catch(\Throwable){return PrintifyResearchGateway::error('catalog_scope',403);}
        // Original controller still enforces nonce/owner caps, exact body/query, and repository policy.
        return $result;
    }
}
