<?php
declare(strict_types=1);
require_once __DIR__.'/Support/ReviewedLocalFixture.php';
use DigiForge\Portal\Portal;
use DigiForge\Core\Settings;

/** Native selected-shop portal + real REST dispatch, with no external operations. */
final class ReviewedContractsPortalAcceptanceTest extends WP_UnitTestCase
{
    private array $actors;
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();
        $this->actors=ReviewedLocalFixture::actors();
        (new DigiForge\REST\ReviewedContractsController())->register();
        (new DigiForge\Security\ShopAccessRestGuard())->register();
        do_action('rest_api_init');
    }
    private function dispatch(string $method,string $shop,array $body=[],string $suffix=''): WP_REST_Response
    {
        $r=new WP_REST_Request($method,'/digiforge/v1/reviewed-contracts/'.$shop.'/policy'.$suffix);
        $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
        if($method==='POST'){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}
        else{$r->set_query_params($body);}
        return rest_get_server()->dispatch($r);
    }
    public function testActualScopedDispatchReviewsOnceAndRejectsForeignShopAndStaleRevision(): void
    {
        $input=['environment'=>'production','scope'=>'SHOP','workflow'=>'','capability'=>'research','enabled'=>true];
        $body=['input'=>$input,'effective_at'=>time()-1,'expires_at'=>time()+600,'request_id'=>wp_generate_uuid4(),'expected_revision'=>0];
        self::assertSame(403,$this->dispatch('POST','jewelry',$body)->get_status());
        $created=$this->dispatch('POST','digital',$body);self::assertSame(201,$created->get_status(),wp_json_encode($created->get_data()));$p=$created->get_data();
        $decision=['key'=>$p['policy_key'],'expected_revision'=>$p['revision'],'proposal_hash'=>$p['proposal_hash'],'decision'=>'APPROVED','request_id'=>wp_generate_uuid4()];
        self::assertSame(409,$this->dispatch('POST','digital',$decision,'/decision')->get_status());
        wp_set_current_user($this->actors['reviewer']);$approved=$this->dispatch('POST','digital',$decision,'/decision');self::assertSame(200,$approved->get_status(),wp_json_encode($approved->get_data()));
        self::assertSame(409,$this->dispatch('POST','digital',$decision,'/decision')->get_status());
        self::assertSame(403,$this->dispatch('GET','jewelry',['key'=>$p['policy_key']])->get_status());
        self::assertSame('APPROVED',$this->dispatch('GET','digital',['key'=>$p['policy_key']])->get_data()['status']);
        ShopAccessFixture::provision([]);
        self::assertSame(403,$this->dispatch('GET','digital',['key'=>$p['policy_key']])->get_status());
        self::assertTrue(Settings::safety_locked());self::assertTrue(Settings::get('stop_all'));self::assertFalse(Settings::get('automation_armed'));
    }
    public function testNativeSelectedShopEditorHasExplicitLabelsAndNoActivationControls(): void
    {
        $_GET=['df_view'=>'ai_budget','df_shop'=>'digital'];
        try {$html=(new Portal())->render();} finally {$_GET=[];}
        self::assertStringContainsString('data-reviewed-contracts',$html);
        self::assertStringContainsString('data-contract-kind="policy"',$html);
        self::assertStringContainsString('data-contract-kind="ownership"',$html);
        self::assertStringContainsString('role="status" aria-live="polite"',$html);
        self::assertStringContainsString('Separate authorized review is mandatory',$html);
        self::assertStringContainsString('for="df-contract-policy-effective_at"',$html);
        self::assertStringContainsString('Selected shop: <strong>digital</strong>',$html);
        self::assertStringNotContainsString('name="account_reference"',$html);
        self::assertStringNotContainsString('name="access_token"',$html);
        self::assertStringNotContainsString('Activate provider',$html);
        $dir=getenv('DF_REVIEWED_PORTAL_FIXTURE_DIR');
        if(is_string($dir)&&$dir!==''){self::assertSame(strlen($html),file_put_contents($dir.'/reviewed-contracts.html',$html));}
        $_GET=['df_view'=>'ai_budget','df_shop'=>'jewelry'];try{$foreign=(new Portal())->render();}finally{$_GET=[];}
        self::assertStringNotContainsString('data-reviewed-contracts',$foreign);
    }
}
