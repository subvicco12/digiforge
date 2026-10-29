<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

/** Canonical Product Factory REST authority/error boundaries under synthetic assignments. */
final class ProductFactoryScopedRestAcceptanceTest extends WP_UnitTestCase
{
    private int $calls=0;
    private Closure $http;
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        (new DigiForge\REST\ProductFactoryController())->register();do_action('rest_api_init');(new DigiForge\Security\ShopAccessRestGuard())->register();
        $this->http=function(){++$this->calls;return new WP_Error('offline_only');};add_filter('pre_http_request',$this->http);
    }
    protected function tearDown():void
    {
        remove_filter('pre_http_request',$this->http);self::assertSame(0,$this->calls);
        self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));parent::tearDown();
    }
    private function hierarchy(string $shop):array
    {
        $r=new DigiForge\ProductFactory\Repository();$key=wp_generate_uuid4();
        $opportunity=$r->create('opportunity',['title'=>'Synthetic '.$key]);self::assertNotWPError($opportunity);
        $candidate=(new DigiForge\Research\Repository())->createCandidate(['title'=>'Synthetic '.$key,'shop_key'=>$shop]);self::assertNotWPError($candidate);
        global $wpdb;self::assertSame(1,$wpdb->update(DigiForge\Database\Tables::research_candidates(),['opportunity_id'=>$opportunity['id']],['id'=>$candidate['id']]));
        $family=$r->create('product_family',['name'=>'Synthetic '.$key,'opportunity_id'=>$opportunity['id']]);self::assertNotWPError($family);
        $product=$r->create('product',['name'=>'Synthetic '.$key,'product_family_id'=>$family['id']]);self::assertNotWPError($product);
        $version=$r->create('product_version',['product_id'=>$product['id'],'version_label'=>'Synthetic '.$key]);self::assertNotWPError($version);
        return ['opportunities'=>$opportunity,'product-families'=>$family,'products'=>$product,'product-versions'=>$version];
    }
    public static function routes():array{return [['opportunities','opportunities'],['product-families','product_families'],['products','products'],['product-versions','product_versions']];}
    private function read(string $route,int $id,?string $claim=null):WP_REST_Response
    {
        $request=new WP_REST_Request('GET','/digiforge/v1/'.$route.'/'.$id);$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
        if($claim!==null)$request->set_query_params(['shop_key'=>$claim]);return rest_do_request($request);
    }
    /** @dataProvider routes */
    public function testAlternateReadCapabilityUsesItsOwnExactShop(string $route,string $tableMethod):void
    {
        $digital=$this->hierarchy('digital');$pod=$this->hierarchy('personalized_pod');$foreign=$this->hierarchy('jewelry');$actor=get_current_user_id();
        ShopAccessFixture::authorizeActor($actor,['digital'],['manage_digiforge_products']);
        ShopAccessFixture::authorizeActor($actor,['personalized_pod'],['manage_digiforge']);
        $response=$this->read($route,(int)$pod[$route]['id']);self::assertSame(200,$response->get_status());self::assertSame($pod[$route]['id'],$response->get_data()['id']);
        self::assertSame(200,$this->read($route,(int)$digital[$route]['id'])->get_status());
        self::assertSame(403,$this->read($route,(int)$foreign[$route]['id'])->get_status());
        self::assertSame(403,$this->read($route,(int)$pod[$route]['id'],'digital')->get_status());
        self::assertSame(403,rest_do_request(new WP_REST_Request('GET','/digiforge/v1/'.$route))->get_status());
        $write=new WP_REST_Request('POST','/digiforge/v1/'.$route.'/'.$pod[$route]['id'].'/state');$write->set_param('state','REVIEW_REQUIRED');
        self::assertSame(403,rest_do_request($write)->get_status(),'Alternate read authority cannot authorize a write.');
    }
    /** @dataProvider routes */
    public function testCompletedRevocationBeforeCallbackPreventsDisclosure(string $route,string $tableMethod):void
    {
        $own=$this->hierarchy('digital');$actor=get_current_user_id();$owner=ShopAccessFixture::authorizeActor($actor,['digital'],['manage_digiforge_products']);
        $repo=new DigiForge\Security\ShopAccessRepository();$state=$repo->read('digital');self::assertNotWPError($state);$grants=array_filter($state['grants'],static fn(array $g):bool=>$g['actor_id']===$actor);self::assertNotEmpty($grants);
        $path='/digiforge/v1/'.$route.'/'.$own[$route]['id'];$changed=false;
        $revoke=function($result,$handler,WP_REST_Request $request)use($path,$owner,$actor,$repo,$state,$grants,&$changed){
            if($request->get_route()===$path&&!$changed){$changed=true;wp_set_current_user($owner);try{self::assertNotWPError($repo->revoke('digital',(string)array_key_first($grants),wp_generate_uuid4(),$state['revision']));}finally{wp_set_current_user($actor);}}
            return $result;
        };
        add_filter('rest_request_before_callbacks',$revoke,11,3);
        try{$response=$this->read($route,(int)$own[$route]['id']);}finally{remove_filter('rest_request_before_callbacks',$revoke,11);}
        self::assertTrue($changed);self::assertSame(403,$response->get_status());self::assertArrayNotHasKey('id',$response->get_data());
    }
    /** @dataProvider routes */
    public function testFailedEntityLookupCannotBecomeHttpSuccess(string $route,string $tableMethod):void
    {
        $own=$this->hierarchy('digital');ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_products']);
        $table=DigiForge\Database\Tables::$tableMethod();$failed=false;
        $fail=static function(string $sql)use($table,&$failed):string{if(str_starts_with($sql,'SELECT * FROM '.$table.' WHERE id = ')){$failed=true;return str_replace($table,$table.'_unavailable',$sql);}return $sql;};
        global $wpdb;$previous=$wpdb->suppress_errors(true);add_filter('query',$fail);
        try{$response=$this->read($route,(int)$own[$route]['id']);}finally{remove_filter('query',$fail);$wpdb->suppress_errors($previous);$wpdb->last_error='';}
        self::assertTrue($failed);self::assertSame(503,$response->get_status());self::assertSame('digiforge_evidence_unavailable',$response->get_data()['code']);
    }
    public function testAlternativeScopeUnionCannotAuthorizeGlobalCollectionsOrCompoundWrites():void
    {
        $actor=get_current_user_id();ShopAccessFixture::authorizeActor($actor,['digital'],['manage_digiforge_products','manage_digiforge_connections']);
        ShopAccessFixture::authorizeActor($actor,['personalized_pod','standard_pod','jewelry'],['manage_digiforge','manage_digiforge_orders']);
        self::assertSame(403,rest_do_request(new WP_REST_Request('GET','/digiforge/v1/products'))->get_status(),'No single complete alternative covers all shops.');
        self::assertSame(403,rest_do_request(new WP_REST_Request('GET','/digiforge/v1/etsy/operations'))->get_status(),'AND capabilities cannot be pooled across shops.');
    }
    public function testAmbiguousStoredTargetAcrossAlternativesStaysDenied():void
    {
        $own=$this->hierarchy('digital');$actor=get_current_user_id();
        $other=(new DigiForge\Research\Repository())->createCandidate(['title'=>'Synthetic conflicting ancestry '.wp_generate_uuid4(),'shop_key'=>'personalized_pod']);self::assertNotWPError($other);
        global $wpdb;self::assertSame(1,$wpdb->update(DigiForge\Database\Tables::research_candidates(),['opportunity_id'=>$own['opportunities']['id']],['id'=>$other['id']]));
        ShopAccessFixture::authorizeActor($actor,['digital'],['manage_digiforge_products']);ShopAccessFixture::authorizeActor($actor,['personalized_pod'],['manage_digiforge']);
        $response=$this->read('products',(int)$own['products']['id']);self::assertSame(403,$response->get_status());self::assertArrayNotHasKey('id',$response->get_data());
    }

    private function dispatchAfterRevocation(WP_REST_Request $request,int $owner,int $actor):WP_REST_Response
    {
        $repository=new DigiForge\Security\ShopAccessRepository();$state=$repository->read('digital');self::assertNotWPError($state);
        $grants=array_filter($state['grants'],static fn(array $g):bool=>$g['actor_id']===$actor);self::assertNotEmpty($grants);$changed=false;
        $revoke=function($result,$handler,WP_REST_Request $incoming)use($request,$repository,$state,$grants,$actor,$owner,&$changed){
            if($incoming->get_route()===$request->get_route()&&!$changed){$changed=true;wp_set_current_user($owner);try{self::assertNotWPError($repository->revoke('digital',(string)array_key_first($grants),wp_generate_uuid4(),$state['revision']));}finally{wp_set_current_user($actor);}}
            return $result;
        };
        add_filter('rest_request_before_callbacks',$revoke,11,3);
        try{$response=rest_do_request($request);}finally{remove_filter('rest_request_before_callbacks',$revoke,11);}
        self::assertTrue($changed);return $response;
    }
    /** @dataProvider routes */
    public function testCompletedRevocationCannotChangeCanonicalState(string $route,string $tableMethod):void
    {
        $own=$this->hierarchy('digital');$actor=get_current_user_id();$owner=ShopAccessFixture::authorizeActor($actor,['digital'],['manage_digiforge_products']);
        $next=['opportunities'=>'QUALIFIED','product-families'=>'ACTIVE','products'=>'READY','product-versions'=>'REVIEW'];
        $request=new WP_REST_Request('POST','/digiforge/v1/'.$route.'/'.$own[$route]['id'].'/state');$request->set_param('state',$next[$route]);
        $response=$this->dispatchAfterRevocation($request,$owner,$actor);self::assertSame(403,$response->get_status());
        global $wpdb;$table=DigiForge\Database\Tables::$tableMethod();self::assertSame($own[$route]['state'],$wpdb->get_var($wpdb->prepare('SELECT state FROM '.$table.' WHERE id=%d',$own[$route]['id'])));
    }
    public function testCompletedRevocationCannotCreateAnUnscopedRecord():void
    {
        $actor=get_current_user_id();$owner=ShopAccessFixture::authorizeActor($actor,DigiForge\Security\ShopAccessPolicy::canonicalShops(),['manage_digiforge_products']);
        $title='Synthetic denied '.wp_generate_uuid4();$request=new WP_REST_Request('POST','/digiforge/v1/opportunities');$request->set_header('Content-Type','application/json');$request->set_body(wp_json_encode(['title'=>$title]));
        $response=$this->dispatchAfterRevocation($request,$owner,$actor);self::assertSame(403,$response->get_status());
        global $wpdb;self::assertSame('0',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::opportunities().' WHERE title=%s',$title)));
    }
    public function testCompletedRevocationCannotReadGlobalCollection():void
    {
        $this->hierarchy('digital');$actor=get_current_user_id();$owner=ShopAccessFixture::authorizeActor($actor,DigiForge\Security\ShopAccessPolicy::canonicalShops(),['manage_digiforge_products']);
        $response=$this->dispatchAfterRevocation(new WP_REST_Request('GET','/digiforge/v1/products'),$owner,$actor);
        self::assertSame(403,$response->get_status());self::assertArrayNotHasKey('items',$response->get_data());
    }

    public function testCompleteAlternateGlobalReadGrantCannotAuthorizeWrite():void
    {
        $actor=get_current_user_id();ShopAccessFixture::authorizeActor($actor,['digital'],['manage_digiforge_products']);
        ShopAccessFixture::authorizeActor($actor,DigiForge\Security\ShopAccessPolicy::canonicalShops(),['manage_digiforge']);
        self::assertSame(200,rest_do_request(new WP_REST_Request('GET','/digiforge/v1/products'))->get_status());
        $request=new WP_REST_Request('POST','/digiforge/v1/opportunities');$request->set_header('Content-Type','application/json');$request->set_body(wp_json_encode(['title'=>'Synthetic forbidden global write']));
        self::assertSame(403,rest_do_request($request)->get_status());
    }
    public function testTargetQueryFailureCannotFallBackToAnotherAlternative():void
    {
        $own=$this->hierarchy('personalized_pod');$actor=get_current_user_id();ShopAccessFixture::authorizeActor($actor,['digital'],['manage_digiforge_products']);ShopAccessFixture::authorizeActor($actor,['personalized_pod'],['manage_digiforge']);
        $table=DigiForge\Database\Tables::products();$failed=false;$fail=static function(string $sql)use($table,&$failed):string{if(str_starts_with($sql,'SELECT '.$table.'.id FROM '.$table.' WHERE ')){$failed=true;return str_replace($table,$table.'_unavailable',$sql);}return $sql;};
        global $wpdb;$previous=$wpdb->suppress_errors(true);add_filter('query',$fail);
        try{$response=$this->read('products',(int)$own['products']['id']);}finally{remove_filter('query',$fail);$wpdb->suppress_errors($previous);$wpdb->last_error='';}
        self::assertTrue($failed);self::assertSame(503,$response->get_status());self::assertArrayNotHasKey('id',$response->get_data());
    }

}
