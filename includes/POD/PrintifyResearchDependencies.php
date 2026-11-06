<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Read-only compatibility diagnostics. Presence never supplies ownership, approval or execution authority. */
final class PrintifyResearchDependencies
{
    public static function requirements(): array
    {
        return [
            PrintifyCatalogReadRepository::class=>['reader','targetShopForGrant','research','evidence','authorizeReservedAttempt'],
            PrintifyCatalogReadPolicy::class=>['authorizeReserved'],
            PrintifyCatalogReadContract::class=>['normalize','endpoint','select','containsProtectedMaterial'],
            PrintifyCatalogGetSafetyExceptionContract::class=>['normalize'],
            PrintifyCatalogClient::class=>['scopedRead'],
            PrintifyConnectionBoundary::class=>['context','authorize'],
            ScopedProviderConnection::class=>['verified'],
            \DigiForge\Security\ShopAccess::class=>['authorize','allowedShops'],
            \DigiForge\Security\ShopAccessPolicy::class=>['validShop','canonicalShops'],
            \DigiForge\Security\ShopAccessRepository::class=>['read','verifyGrantEvidence','authorityFor'],
            \DigiForge\Security\ShopAccessAuthority::class=>['records'],
            \DigiForge\Security\FirstPartyOwnerPolicy::class=>['records'],
            \DigiForge\Security\ReviewedLocalEvidence::class=>['read','approved'],
            \DigiForge\Database\ProviderConnectionSchema::class=>['table'],
            \DigiForge\Database\QueryEvidence::class=>['failed'],
        ];
    }
    public static function routes(): array
    {
        $base='/digiforge/v1/shops/personalized_pod/printify-research';$sha=str_repeat('a',64);
        return [
            ['method'=>'GET','route'=>$base.'/readiness'],
            ['method'=>'GET','route'=>$base.'/snapshots/'.$sha],
            ['method'=>'POST','route'=>$base.'/receipts'],
            ['method'=>'POST','route'=>$base.'/batches'],
            ['method'=>'GET','route'=>$base.'/batches/'.$sha],
            ['method'=>'POST','route'=>$base.'/batches/'.$sha.'/step'],
        ];
    }
    public static function matchesRoute(string $route,string $method): bool
    {
        foreach(self::routes() as $known){
            $pattern='#^'.str_replace(str_repeat('a',64),'[a-f0-9]{64}',preg_quote($known['route'],'#')).'$#D';
            if($method===$known['method']&&preg_match($pattern,$route)===1)return true;
        }
        return false;
    }
    public static function actions(): array
    {
        return [
            'digiforge_pod_intelligence_import'=>['manage_digiforge','manage_digiforge_pod','manage_digiforge_research'],
            'digiforge_pod_intelligence_export'=>['manage_digiforge','manage_digiforge_pod'],
            'digiforge_pod_research_prepare'=>self::caps(),
            'digiforge_pod_research_step'=>self::caps(),
        ];
    }
    private static function caps(): array {return ['manage_digiforge','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_connections'];}
    /** Preserve existing main permissions; installed scoped authority must return literal true. */
    public static function assigned(array $caps): bool
    {
        $class=\DigiForge\Security\ShopAccess::class;if(!class_exists($class))return true;
        try{foreach($caps as $cap)if($class::authorize('personalized_pod',$cap)!==true)return false;return true;}
        catch(\Throwable){return false;}
    }
    public static function inspect(): array
    {
        $missing=[];
        foreach(self::requirements() as $class=>$methods){
            $short=substr($class,strrpos($class,'\\')+1);
            if(!class_exists($class)){$missing[]=$short;continue;}
            foreach($methods as $method)if(!is_callable([$class,$method]) && !(method_exists($class,$method)&&(new \ReflectionMethod($class,$method))->isPublic()))$missing[]=$short.'::'.$method;
        }
        $routes=[];$guardClass=\DigiForge\Security\ShopAccessRestGuard::class;
        $targetClass=\DigiForge\Security\ShopAccessTargetScope::class;
        $guardPresent=class_exists($guardClass)||class_exists($targetClass);
        // A narrow approved dependency may provide the core without installing the
        // cumulative lane's global guards. Our handlers still enforce ShopAccess.
        $rest=!$guardPresent;$targets=!$guardPresent;
        if($missing===[]&&$guardPresent&&is_callable([$guardClass,'capabilitySets'])&&is_callable([$targetClass,'forRequest'])&&class_exists(\WP_REST_Request::class)){
            $rest=true;$targets=true;
            foreach(self::routes() as $route){
                try{
                    $sets=\DigiForge\Security\ShopAccessRestGuard::capabilitySets($route['route'],$route['method']);$mapped=false;
                    foreach($sets as $set)if(is_array($set)&&array_diff(self::caps(),$set)===[])$mapped=true;
                    $request=new \WP_REST_Request($route['method'],$route['route']);
                    $target=\DigiForge\Security\ShopAccessTargetScope::forRequest($request,['personalized_pod']);
                    $fixed=$target==='personalized_pod';$rest=$rest&&$mapped;$targets=$targets&&$fixed;
                    $routes[]=$route+['capabilities_mapped'=>$mapped,'fixed_shop_target'=>$fixed];
                }catch(\Throwable){$rest=false;$targets=false;$routes[]=$route+['capabilities_mapped'=>false,'fixed_shop_target'=>false];}
            }
        }
        // Proposed pure owner interface. No source parsing, exemption hooks or policy overrides.
        // Until the shared owner supplies this contract, form admission remains unverified.
        $adminClass=\DigiForge\Security\ShopAccessAdminGuard::class;
        $admin=!class_exists($adminClass);
        if(class_exists($adminClass)&&is_callable([$adminClass,'scopeForAction'])){
            $admin=true;foreach(self::actions() as $action=>$caps){try{$scope=$adminClass::scopeForAction($action);if(!is_array($scope)||($scope['shop']??null)!=='personalized_pod'||!is_array($scope['caps']??null)||array_diff($caps,$scope['caps'])!==[])$admin=false;}catch(\Throwable){$admin=false;}}
        }
        $sizes=false;
        if(class_exists(PrintifyCatalogReadContract::class))try{$sizes=PrintifyCatalogReadContract::endpoint(['kind'=>'size_guide','blueprint_id'=>1])==='/v1/catalog/blueprints/1/size_guide.json';}catch(\Throwable){}
        $ready=$missing===[]&&$rest&&$targets;
        return ['pipeline_state'=>$missing!==[]?'GOVERNED_PIPELINE_UNAVAILABLE':($ready?'GOVERNED_PIPELINE_PRESENT':'GOVERNED_PIPELINE_INTEGRATION_BLOCKED'),'integration_ready'=>$ready,'portal_controls_ready'=>$ready&&$admin,'missing_dependencies'=>$missing,'shared_global_guards_present'=>$guardPresent,'shared_admin_guard_present'=>class_exists($adminClass),'dependency_approval_asserted'=>false,'database_schema_checked'=>false,'runtime_authorization_checked'=>false,'rest_admission_mapped'=>$rest,'fixed_shop_target_mapped'=>$targets,'admin_action_scope_verified'=>$admin,'routes'=>$routes,'size_guide_grant_scope_supported'=>$sizes,'max_requests_per_grant'=>10,'automatic_retry'=>false,'external_execution_authorized'=>false,'readiness_is_not_authorization'=>true];
    }
}
