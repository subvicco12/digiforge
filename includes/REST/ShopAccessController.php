<?php
declare(strict_types=1);
namespace DigiForge\REST;

use DigiForge\Security\ShopAccess;
use DigiForge\Security\ShopAccessRepository;

/** Local assignments only. No route can install or create trusted owner authority. */
final class ShopAccessController
{
    public function register(): void
    {
        add_action('rest_api_init', function(): void {
            register_rest_route('digiforge/v1','/shop-access/shops',['methods'=>'GET','permission_callback'=>[$this,'signedIn'],'callback'=>[$this,'shops']]);
            register_rest_route('digiforge/v1','/shop-access/grants',[
                ['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'grant']],
                ['methods'=>'GET','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'assignments']],
            ]);
            register_rest_route('digiforge/v1','/shop-access/grants/(?P<grant_id>[a-f0-9]{64})/revoke',['methods'=>'POST','permission_callback'=>[$this,'canManage'],'callback'=>[$this,'revoke']]);
        });
    }
    public function signedIn(): bool { return get_current_user_id()>0; }
    public function canManage(): true|\WP_Error
    {
        if (!$this->signedIn()) return self::error('authentication',401);
        return current_user_can('manage_digiforge_settings')?true:self::error('permission',403);
    }
    public function shops(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $cap=$request->get_param('capability')??'manage_digiforge';
        if (!is_string($cap)||!in_array($cap,\DigiForge\Core\Capabilities::ALL,true)) return self::error('typed',400);
        $shops=ShopAccess::allowedShops($cap);
        return $shops instanceof \WP_Error?$shops:new \WP_REST_Response(['shops'=>$shops,'capability'=>$cap,'implicit_access'=>false,'external_execution_authorized'=>false]);
    }
    public function grant(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $p=$this->body($request,['subject_id','shop','role','caps','expires_at','request_id','expected_revision']);
        if ($p instanceof \WP_Error) return $p;
        if (!is_int($p['subject_id']??null)||!is_string($p['role']??null)||!is_array($p['caps']??null)||!is_int($p['expires_at']??null)) return self::error('typed',400);
        $r=(new ShopAccessRepository())->grant($p['subject_id'],$p['shop'],$p['role'],$p['caps'],$p['expires_at'],$p['request_id'],$p['expected_revision']);
        return $r instanceof \WP_Error?$r:new \WP_REST_Response($r,201);
    }
    public function assignments(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $permission=$this->canManage();if ($permission instanceof \WP_Error) return $permission;
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'),'wp_rest')) return self::error('nonce',403);
        $query=$request->get_query_params();$shop=$query['shop_key']??null;
        if (count($query)!==1||!is_string($shop)||!\DigiForge\Security\ShopAccessPolicy::validShop($shop)) return self::error('typed',400);
        $authority=ShopAccessRepository::authorityFor(get_current_user_id(),$shop,[],time()+1);
        if ($authority instanceof \WP_Error) return self::error('authority',403);
        $state=(new ShopAccessRepository())->read($shop);if ($state instanceof \WP_Error) return $state;
        $items=[];foreach ($state['grants'] as $id=>$grant) {
            // Delegates see assignment metadata only within their signed delegation scope.
            if (array_diff($grant['caps'],$authority['record']['caps'])!==[]) continue;
            $items[]=['grant_id'=>$id]+array_intersect_key($grant,array_flip(['actor_id','role','caps','expires_at','revoked']));
        }
        return new \WP_REST_Response(['shop'=>$shop,'revision'=>$state['revision'],'items'=>$items,'business_access_granted'=>false,'external_execution_authorized'=>false]);
    }
    public function revoke(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $p=$this->body($request,['shop','request_id','expected_revision']);if ($p instanceof \WP_Error) return $p;
        $id=$request->get_param('grant_id');if (!is_string($id)||preg_match('/^[a-f0-9]{64}$/D',$id)!==1) return self::error('typed',400);
        $r=(new ShopAccessRepository())->revoke($p['shop'],$id,$p['request_id'],$p['expected_revision']);
        return $r instanceof \WP_Error?$r:new \WP_REST_Response($r,200);
    }
    private function body(\WP_REST_Request $request,array $fields): array|\WP_Error
    {
        $permission=$this->canManage();if ($permission instanceof \WP_Error) return $permission;
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'),'wp_rest')) return self::error('nonce',403);
        if (strlen($request->get_body())>16384) return self::error('bounded',413);
        $p=$request->get_json_params();
        if (!is_array($p)||count($p)!==count($fields)||array_diff(array_keys($p),$fields)!==[]||!is_string($p['shop']??null)||!is_string($p['request_id']??null)||!is_int($p['expected_revision']??null)) return self::error('typed',400);
        return $p;
    }
    private static function error(string $code,int $status): \WP_Error
    {
        if (!\DigiForge\Security\Logger::write('shop_access_management_denied',['actor_id'=>get_current_user_id(),'reason'=>$code],'shop_access','management')) return new \WP_Error('digiforge_shop_access_audit','Access denial audit unavailable.',['status'=>503]);
        return new \WP_Error('digiforge_shop_access_'.$code,'An exact authenticated local assignment request is required.',['status'=>$status]);
    }
}
