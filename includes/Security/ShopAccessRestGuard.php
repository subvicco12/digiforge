<?php
declare(strict_types=1);
namespace DigiForge\Security;

/** Admission is additional to route permission, nonce, target and execution interlocks. */
final class ShopAccessRestGuard
{
    public function register(): void
    {
        add_filter('rest_request_before_callbacks', [$this, 'admit'], 9, 3);
    }

    public function admit(mixed $result, array $handler, \WP_REST_Request $request): mixed
    {
        if ($result instanceof \WP_Error) return $result;
        $route=$request->get_route();
        if (!str_starts_with($route, '/digiforge/v1/')) return $result;
        // Exact independently authenticated boundaries, not general provider/customer access.
        if ($request->get_method()==='POST' && $route==='/digiforge/v1/etsy/webhook') return $result;
        if ($request->get_method()==='GET' && in_array($route, [
            '/digiforge/v1/health', '/digiforge/v1/recovery/verification-snapshot',
        ], true)) return $result;
        if (preg_match('#^/digiforge/v1/recovery/evidence/database-backup/marker/[a-f0-9]{64}$#D', $route) && $request->get_method()==='GET') return $result;
        // Existing customer sessions are explicit actor/order grants. Their callbacks still
        // require the assigned customer, cookie nonce, session, revision and exact order.
        if (preg_match('#^/digiforge/v1/customer-editor/[A-Za-z0-9_-]{1,80}/(view|upload|fields|approve)$#D', $route,$customer) && $request->get_method()===($customer[1]==='view'?'GET':'POST')) return $result;
        // Assignment management authenticates independent OWNER/DELEGATE authority itself.
        if (($route==='/digiforge/v1/shop-access/shops' && $request->get_method()==='GET') ||
            ($route==='/digiforge/v1/shop-access/grants' && in_array($request->get_method(),['GET','POST'],true)) ||
            (preg_match('#^/digiforge/v1/shop-access/grants/[a-f0-9]{64}/revoke$#D',$route) && $request->get_method()==='POST')) return $result;
        // Finance remains governed by its existing permissions and deferred workstream.
        if (str_starts_with($route, '/digiforge/v1/finance/')) return $result;
        if (get_current_user_id()<1) {
            if (!Logger::write('shop_access_rest_denied',['reason'=>'authentication','actor_id'=>0],'shop_access','rest')) return new \WP_Error('digiforge_shop_access_audit','Access denial audit unavailable.',['status'=>503]);
            return new \WP_Error('digiforge_shop_access_auth','Authentication is required.',['status'=>401]);
        }
        $sets=self::capabilitySets($route, $request->get_method());
        if ($sets===[]) return self::deny('unmapped');
        $alternatives=[];$targetShops=[];
        foreach ($sets as $caps) {
            $allowed=ShopAccessPolicy::canonicalShops();
            foreach ($caps as $cap) {
                $granted=ShopAccess::allowedShops($cap);
                if ($granted instanceof \WP_Error) return $granted;
                $allowed=array_values(array_intersect($allowed,$granted));
            }
            if ($allowed===[]) continue;
            $alternatives[]=['caps'=>$caps,'shops'=>$allowed];
            $targetShops=array_values(array_unique(array_merge($targetShops,$allowed)));
        }
        if ($alternatives===[]) return self::deny('denied');
        // Resolve one exact stored target across the existing OR alternatives. A
        // different shop's first capability must not veto a valid read alternative.
        $target=ShopAccessTargetScope::forRequest($request, $targetShops);
        if ($target instanceof \WP_Error) return $target;
        foreach ($alternatives as $alternative) {
            $caps=$alternative['caps'];$allowed=$alternative['shops'];
            if (is_string($target)) {
                if (!in_array($target,$allowed,true)) continue;
                foreach ($caps as $cap) { $decision=ShopAccess::authorize($target,$cap);if ($decision instanceof \WP_Error) return $decision; }
                return $result;
            }
            // A supplied shop does not scope an existing global collection or connector.
            if (count($allowed)===count(ShopAccessPolicy::canonicalShops())) return $result;
        }
        return self::deny('denied');
    }

    public static function capabilitySets(string $route, string $method): array
    {
        $path=substr($route, strlen('/digiforge/v1/'));
        return match (true) {
            preg_match('#^reviewed-contracts/(digital|personalized_pod)/acceptance(?:/(proposal|decision))?$#D',$path,$acceptance)===1 => match (true) {
                !isset($acceptance[2]) && $method==='GET' => [['manage_digiforge']],
                (!isset($acceptance[2]) && $method==='POST') || (($acceptance[2]??null)==='proposal' && $method==='GET') || (($acceptance[2]??null)==='decision' && $method==='POST') => [['manage_digiforge','manage_digiforge_settings']],
                default => [],
            },
            preg_match('#^reviewed-contracts/(digital|personalized_pod|standard_pod|jewelry)/(policy|ownership)(/decision)?$#D',$path,$contract)===1 => $method==='GET'?[['manage_digiforge']]:[['manage_digiforge',isset($contract[3])||$contract[2]==='policy'?'manage_digiforge_settings':'manage_digiforge_connections']],
            str_starts_with($path,'printify/catalog-read/')=>[['manage_digiforge_connections','manage_digiforge_pod']],
            $path==='etsy/operations'=>[['manage_digiforge_connections','manage_digiforge_orders']],
            $path==='etsy/publish-listing'=>[['manage_digiforge_connections','manage_digiforge_listings']],
            $path==='launch/status'=>[['manage_digiforge']],
            str_starts_with($path,'launch/research')=>[['manage_digiforge_research','manage_digiforge_ai']],
            str_ends_with($path,'/prepare-listing')&&str_starts_with($path,'launch/')=>[['manage_digiforge_products','manage_digiforge_production','manage_digiforge_listings']],
            str_ends_with($path,'/build-product')&&str_starts_with($path,'launch/')=>[['manage_digiforge_products','manage_digiforge_ai','manage_digiforge_production']],
            str_ends_with($path,'/review')&&str_starts_with($path,'launch/')=>[['manage_digiforge_products','manage_digiforge_production']],
            str_starts_with($path,'launch/')=>[['manage_digiforge_products','manage_digiforge_ai']],
            str_starts_with($path,'orders')=>[['manage_digiforge_orders']],
            str_starts_with($path,'research')=>[['manage_digiforge_research']],
            str_starts_with($path,'ai/')=>[['manage_digiforge_ai']],
            str_starts_with($path,'production')=>[['manage_digiforge_production']],
            str_starts_with($path,'digital')=>[['manage_digiforge_digital']],
            str_starts_with($path,'listings')=>[['manage_digiforge_listings']],
            str_starts_with($path,'integrations'),str_starts_with($path,'printify/')=>[['manage_digiforge_connections']],
            str_starts_with($path,'pod/'),str_starts_with($path,'customer-editor/')=>[['manage_digiforge_pod']],
            str_starts_with($path,'etsy/')=>[['manage_digiforge_connections']],
            preg_match('#^(opportunities|product-families|products|product-versions)(/|$)#D',$path)===1=>$method==='GET'?[['manage_digiforge_products'],['manage_digiforge']]:[['manage_digiforge_products']],
            $path==='readiness'&&$method==='GET'=>[['manage_digiforge'],['view_digiforge_analytics']],
            preg_match('#^(recovery|controls|protection|activations|internal-smoke)(/|$)#D',$path)===1=>[['manage_digiforge_automation']],
            default=>[],
        };
    }

    private static function deny(string $reason): \WP_Error
    {
        if (!Logger::write('shop_access_rest_denied', ['reason'=>$reason,'actor_id'=>get_current_user_id()], 'shop_access', 'rest')) return new \WP_Error('digiforge_shop_access_audit','Access denial could not be audited.',['status'=>503]);
        return new \WP_Error('digiforge_shop_access_denied','Explicit current shop access is required for this route.',['status'=>403]);
    }
}
