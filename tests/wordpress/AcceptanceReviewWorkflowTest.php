<?php
declare(strict_types=1);
require_once __DIR__.'/Support/ReviewedLocalFixture.php';

final class AcceptanceReviewWorkflowTest extends WP_UnitTestCase
{
    private array $actors;
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        $this->actors=ReviewedLocalFixture::actors('personalized_pod');
        (new DigiForge\REST\ReviewedContractsController())->register();
        (new DigiForge\Security\ShopAccessRestGuard())->register();
        do_action('rest_api_init');
    }
    private function request(array $body, string $shop='personalized_pod'): WP_REST_Request
    {
        $r=new WP_REST_Request('POST','/digiforge/v1/reviewed-contracts/'.$shop.'/acceptance');
        $r->set_url_params(['shop'=>$shop]);
        $r->set_header('Content-Type','application/json');
        $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
        $r->set_body(wp_json_encode($body));
        return $r;
    }
    private function proposal(): array
    {
        $criterion='BP-portal-7-supplier-readiness';
        return ['input'=>['version'=>2,'criterion'=>$criterion,'scope'=>'synthetic-product','workflow'=>'supplier-review',
            'definition'=>['policy'=>DigiForge\Portal\ApprovedAcceptancePolicy::rules($criterion),'environment'=>'sandbox',
                'subject'=>['scope'=>'PRODUCT_VERSION','product_id'=>'synthetic','product_version'=>'1','supplier_id'=>'synthetic'],
                'period_start'=>'2026-01-01T00:00:00Z','period_end'=>'2027-01-01T00:00:00Z','source_records'=>[],
                'observations'=>null]],'effective_at'=>time()-1,'expires_at'=>time()+600,
            'expected_revision'=>0,'request_id'=>wp_generate_uuid4(),'confirm'=>'CONFIRM_LOCAL_ACCEPTANCE_REVIEW'];
    }
    public function testProposalIndependentDecisionReplayAndRevocationUseExistingLedger(): void
    {
        $controller=new DigiForge\REST\ReviewedContractsController();
        $body=$this->proposal();
        $created=$controller->proposeAcceptance($this->request($body));
        self::assertInstanceOf(WP_REST_Response::class,$created);
        $state=$created->get_data();
        self::assertEquals($body['input'],$state['payload']);
        $read=$this->request([]);$read->set_method('GET');$read->set_body('');
        $read->set_query_params(['criterion'=>$body['input']['criterion'],'scope'=>$body['input']['scope']]);
        $inspected=$controller->inspectAcceptance($read);
        self::assertInstanceOf(WP_REST_Response::class,$inspected);
        self::assertSame($state['proposal_hash'],$inspected->get_data()['proposal_hash']);
        $decision=['criterion'=>$body['input']['criterion'],'scope'=>$body['input']['scope'],
            'expected_revision'=>$state['revision'],'proposal_hash'=>$state['proposal_hash'],
            'decision'=>'APPROVED','request_id'=>wp_generate_uuid4(),'confirm'=>'CONFIRM_LOCAL_ACCEPTANCE_REVIEW'];
        self::assertWPError($controller->decideAcceptance($this->request($decision)));
        wp_set_current_user($this->actors['reviewer']);
        self::assertWPError($controller->decideAcceptance($this->request(array_replace($decision,['proposal_hash'=>str_repeat('a',64)]))));
        $approved=$controller->decideAcceptance($this->request($decision));
        self::assertInstanceOf(WP_REST_Response::class,$approved);
        self::assertWPError($controller->decideAcceptance($this->request($decision)));
        $decision['expected_revision']=$approved->get_data()['revision'];
        $decision['decision']='REVOKED';$decision['request_id']=wp_generate_uuid4();
        self::assertInstanceOf(WP_REST_Response::class,$controller->decideAcceptance($this->request($decision)));
        self::assertTrue(DigiForge\Core\Settings::get('stop_all'));
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
        self::assertFalse($approved->get_data()['external_execution_authorized']);
    }
    public function testAnonymousNonceForeignQueryWrongMethodAndMalformedInputCannotWrite(): void
    {
        $c=new DigiForge\REST\ReviewedContractsController();$body=$this->proposal();
        foreach (['digital','standard_pod','jewelry'] as $shop) self::assertWPError($c->proposeAcceptance($this->request($body,$shop)));
        $r=$this->request($body);$r->set_header('X-WP-Nonce','');self::assertWPError($c->proposeAcceptance($r));
        $r=$this->request($body);$r->set_method('GET');self::assertWPError($c->proposeAcceptance($r));
        $r=$this->request($body);$r->set_query_params(['scope'=>'other']);self::assertWPError($c->proposeAcceptance($r));
        $r=$this->request($body);$r->set_body(str_repeat(' ',32769));self::assertWPError($c->proposeAcceptance($r));
        self::assertWPError($c->proposeAcceptance($this->request(array_replace($body,['confirm'=>'']))));
        wp_set_current_user(0);self::assertWPError($c->proposeAcceptance($this->request($body)));
    }

    public function testRoutesRetainStatusAndAllowPlainPermalinkProposalWithoutScopeOverride(): void
    {
        $c=new DigiForge\REST\ReviewedContractsController();
        $r=$this->request($this->proposal());$r->set_query_params(['rest_route'=>$r->get_route()]);
        self::assertInstanceOf(WP_REST_Response::class,$c->proposeAcceptance($r));
        $r=$this->request($this->proposal());$r->set_query_params(['rest_route'=>'/digiforge/v1/reviewed-contracts/digital/acceptance']);
        self::assertWPError($c->proposeAcceptance($r));
        // Register through the actual WordPress route table: adding POST must preserve GET.
        $c->register();do_action('rest_api_init');
        $routes=rest_get_server()->get_routes();
        $route='/digiforge/v1/reviewed-contracts/(?P<shop>digital|personalized_pod|standard_pod|jewelry)/acceptance';
        self::assertTrue($routes[$route][0]['methods']['GET']);
        $write='/digiforge/v1/reviewed-contracts/(?P<shop>digital|personalized_pod)/acceptance';
        self::assertTrue($routes[$write][0]['methods']['POST']);
        $body=$this->proposal();$body['input']['scope']='dispatch-scope';
        $created=rest_do_request($this->request($body));
        self::assertSame(201,$created->get_status());
        $state=$created->get_data();
        $read=$this->request([]);$read->set_method('GET');$read->set_body('');
        $read->set_route('/digiforge/v1/reviewed-contracts/personalized_pod/acceptance/proposal');
        $read->set_query_params(['criterion'=>$body['input']['criterion'],'scope'=>'dispatch-scope']);
        $inspected=rest_do_request($read);self::assertSame(200,$inspected->get_status());
        self::assertSame($state['proposal_hash'],$inspected->get_data()['proposal_hash']);
        $read->set_header('X-WP-Nonce','');self::assertSame(403,rest_do_request($read)->get_status());
        wp_set_current_user($this->actors['reviewer']);
        $decision=$this->request(['criterion'=>$body['input']['criterion'],'scope'=>'dispatch-scope',
            'expected_revision'=>$state['revision'],'proposal_hash'=>$state['proposal_hash'],'decision'=>'REJECTED',
            'request_id'=>wp_generate_uuid4(),'confirm'=>'CONFIRM_LOCAL_ACCEPTANCE_REVIEW']);
        $decision->set_route('/digiforge/v1/reviewed-contracts/personalized_pod/acceptance/decision');
        self::assertSame(200,rest_do_request($decision)->get_status());
        $status=$this->request([]);$status->set_method('GET');$status->set_body('');
        $status->set_query_params(['criterion'=>$body['input']['criterion'],'scope'=>'dispatch-scope']);
        self::assertSame('REJECTED',rest_do_request($status)->get_data()['status']);
    }

    public static function activeScopes(): array
    {
        return [['digital',false],['digital',true],['personalized_pod',false],['personalized_pod',true]];
    }

    /** @dataProvider activeScopes */
    public function testSingleShopDispatchRetainsAllAdmissionLayers(string $shop,bool $plain): void
    {
        $this->actors=ReviewedLocalFixture::actors($shop);
        $body=$this->proposal();$body['input']['scope']='scoped-dispatch';
        $request=$this->request($body,$shop);
        if ($plain) $request->set_query_params(['rest_route'=>$request->get_route()]);
        $created=rest_do_request($request);self::assertSame(201,$created->get_status());
        $state=$created->get_data();
        $read=$this->request([],$shop);$read->set_method('GET');$read->set_body('');
        $read->set_route('/digiforge/v1/reviewed-contracts/'.$shop.'/acceptance/proposal');
        $read->set_query_params(['criterion'=>$body['input']['criterion'],'scope'=>'scoped-dispatch']+($plain?['rest_route'=>$read->get_route()]:[]));
        self::assertSame($state['proposal_hash'],rest_do_request($read)->get_data()['proposal_hash']);
        wp_set_current_user($this->actors['reviewer']);
        $decision=$this->request(['criterion'=>$body['input']['criterion'],'scope'=>'scoped-dispatch',
            'expected_revision'=>$state['revision'],'proposal_hash'=>$state['proposal_hash'],'decision'=>'APPROVED',
            'request_id'=>wp_generate_uuid4(),'confirm'=>'CONFIRM_LOCAL_ACCEPTANCE_REVIEW'],$shop);
        $decision->set_route('/digiforge/v1/reviewed-contracts/'.$shop.'/acceptance/decision');
        if ($plain) $decision->set_query_params(['rest_route'=>$decision->get_route()]);
        self::assertSame(200,rest_do_request($decision)->get_status());
        self::assertGreaterThanOrEqual(400,rest_do_request($decision)->get_status());
        foreach (['digital','personalized_pod','standard_pod','jewelry'] as $foreign) {
            if ($foreign!==$shop) self::assertGreaterThanOrEqual(400,rest_do_request($this->request($body,$foreign))->get_status());
        }
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
        self::assertTrue(DigiForge\Core\Settings::get('stop_all'));
    }

    public function testDormantShopAssignmentsAndFinanceCapabilityCannotAuthorizeEditor(): void
    {
        foreach (['standard_pod','jewelry'] as $shop) {
            $this->actors=ReviewedLocalFixture::actors($shop);
            self::assertGreaterThanOrEqual(400,rest_do_request($this->request($this->proposal(),$shop))->get_status());
            ob_start();DigiForge\Portal\AcceptanceReviewPanel::render($shop);self::assertSame('',ob_get_clean());
        }
        $id=self::factory()->user->create(['role'=>'administrator']);
        ShopAccessFixture::authorizeActor($id,['digital'],['manage_digiforge','manage_digiforge_finance']);
        wp_set_current_user($id);
        self::assertSame(403,rest_do_request($this->request($this->proposal(),'digital'))->get_status());
    }

    public function testNativeCookieNonceAuthenticationCannotBeReplacedByLoggedInUser(): void
    {
        global $wp_rest_auth_cookie;
        $savedCookie=$wp_rest_auth_cookie??null;$savedHeader=$_SERVER['HTTP_X_WP_NONCE']??null;
        $savedRequest=$_REQUEST;$savedCookies=$_COOKIE;$actor=get_current_user_id();
        $token=WP_Session_Tokens::get_instance($actor)->create(time()+600);
        try {
            $_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($actor,time()+600,'logged_in',$token);
            $wp_rest_auth_cookie=null;wp_set_current_user(0);
            self::assertSame($actor,wp_validate_auth_cookie($_COOKIE[LOGGED_IN_COOKIE],'logged_in'));wp_set_current_user($actor);
            $_REQUEST=[];$_SERVER['HTTP_X_WP_NONCE']=wp_create_nonce('wp_rest');
            self::assertTrue(rest_get_server()->check_authentication());
            self::assertSame(201,rest_do_request($this->request($this->proposal()))->get_status());
            $originalCookie=$_COOKIE[LOGGED_IN_COOKIE];$otherToken=WP_Session_Tokens::get_instance($actor)->create(time()+600);
            try {
                $_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($actor,time()+600,'logged_in',$otherToken);
                $_SERVER['HTTP_X_WP_NONCE']=wp_create_nonce('wp_rest');
                $_COOKIE[LOGGED_IN_COOKIE]=$originalCookie;
                self::assertWPError(rest_get_server()->check_authentication());
            } finally { WP_Session_Tokens::get_instance($actor)->destroy($otherToken);$_COOKIE[LOGGED_IN_COOKIE]=$originalCookie; }
            $_SERVER['HTTP_X_WP_NONCE']='invalid';self::assertWPError(rest_get_server()->check_authentication());
            unset($_SERVER['HTTP_X_WP_NONCE']);self::assertTrue(rest_get_server()->check_authentication());
            self::assertSame(0,get_current_user_id());
        } finally {
            WP_Session_Tokens::get_instance($actor)->destroy($token);wp_set_current_user($actor);
            $wp_rest_auth_cookie=$savedCookie;$_REQUEST=$savedRequest;$_COOKIE=$savedCookies;
            if ($savedHeader===null) unset($_SERVER['HTTP_X_WP_NONCE']);else $_SERVER['HTTP_X_WP_NONCE']=$savedHeader;
        }
    }

    public function testNativeEditorFixturesHaveLabelsAndBoundedReviewControls(): void
    {
        foreach (['digital','personalized_pod'] as $shop) {
            $this->actors=ReviewedLocalFixture::actors($shop);
            ob_start();DigiForge\Portal\ReviewedContractsPanel::render($shop);$html=(string)ob_get_clean();
            self::assertStringContainsString('data-acceptance-review',$html);
            self::assertStringContainsString('data-shop="'.$shop.'"',$html);
            $doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$html);$x=new DOMXPath($doc);
            $editor=$x->query('//*[@data-acceptance-review]')->item(0);self::assertNotNull($editor);
            self::assertSame(3,$x->query('.//form',$editor)->length);self::assertSame(2,$x->query('.//input[@type="checkbox"][@required]',$editor)->length);
            self::assertSame(1,$x->query('.//*[@role="status"][@aria-live="polite"]',$editor)->length);
            $dir=getenv('DF_ACCEPTANCE_REVIEW_FIXTURES');if ($dir) self::assertNotFalse(file_put_contents($dir.'/'.$shop.'.html',$html));
        }
    }
}
