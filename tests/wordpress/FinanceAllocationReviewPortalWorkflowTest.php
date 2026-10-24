<?php
declare(strict_types=1);
use DigiForge\Database\Tables;
use DigiForge\Portal\FinanceAllocationReviewInput;
use DigiForge\Portal\FinanceAllocationReviewWorkflow;
use DigiForge\Security\ShopAccessPolicy;
require_once __DIR__.'/FinanceAllocationStoredTargetReferencesTest.php';
require_once __DIR__.'/Support/ReviewedLocalFixture.php';

/** Synthetic offline local review only; no provider calls or financial approval. */
final class FinanceAllocationReviewPortalWorkflowTest extends WP_UnitTestCase
{
    private array $fixture=[];
    private array $actors=[];
    private string $temporary='';
    private ?Closure $remap=null;
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();global $wpdb;
        $table=Tables::finance_ledger();$this->temporary=$table.'_portal_review_'.substr(str_replace('-','',wp_generate_uuid4()),0,8);
        self::assertNotFalse($wpdb->query('CREATE TEMPORARY TABLE '.$this->temporary.' LIKE '.$table));$temporary=$this->temporary;
        $this->remap=static fn(string $sql):string=>str_replace($table,$temporary,$sql);add_filter('query',$this->remap,99);
        $method=new ReflectionMethod(FinanceAllocationStoredTargetReferencesTest::class,'persistedFixture');$method->setAccessible(true);
        $this->fixture=$method->invoke(new FinanceAllocationStoredTargetReferencesTest());
        $this->actors=ReviewedLocalFixture::actors('personalized_pod');
        foreach(['author','reviewer'] as $actor)ShopAccessFixture::authorizeActor($this->actors[$actor],ShopAccessPolicy::canonicalShops(),['manage_digiforge','manage_digiforge_finance']);
        wp_set_current_user($this->actors['author']);
    }
    protected function tearDown():void
    {
        if($this->remap!==null)remove_filter('query',$this->remap,99);global $wpdb;
        if($this->temporary!=='')$wpdb->query('DROP TEMPORARY TABLE IF EXISTS '.$this->temporary);parent::tearDown();
    }
    private function loadWorkflow():void {self::assertFileExists(dirname(__DIR__,2).'/includes/Portal/FinanceAllocationReviewWorkflow.php');}
    private function post(string $operation='view',?array $state=null):array
    {
        $post=['action'=>FinanceAllocationReviewInput::ACTION,'_wpnonce'=>wp_create_nonce(FinanceAllocationReviewInput::ACTION),'operation'=>$operation,'shop_key'=>'personalized_pod','environment'=>'test','business_id'=>(string)$this->fixture['scope']['business_id'],'store_id'=>(string)$this->fixture['scope']['store_id'],'source_hash'=>$this->fixture['source']['declaration_hash']];
        if($operation==='propose')$post+=['lines_json'=>wp_json_encode([['target'=>$this->fixture['target'],'amount'=>'10','base_amount'=>'10']]),'effective_at'=>(string)(time()-1),'expires_at'=>(string)(time()+600),'expected_revision'=>'0','request_id'=>'portal_allocation_propose_1'];
        elseif($operation!=='view')$post+=['expected_revision'=>(string)$state['review']['revision'],'proposal_hash'=>$state['review']['proposal_hash'],'request_id'=>'portal_allocation_'.$operation.'_1'];
        return $post;
    }
    private function request(string $operation='view',?array $state=null):array|WP_Error|null
    {
        return FinanceAllocationReviewWorkflow::processBrowser($this->post($operation,$state),'personalized_pod');
    }
    public function testIndependentLocalReviewReadAndRevocationStayRedactedAndOffline():void
    {
        $this->loadWorkflow();$calls=0;$http=static function($pre)use(&$calls){$calls++;return new WP_Error('offline','No external requests.');};add_filter('pre_http_request',$http);
        try{
            self::assertNull($this->request());$p=$this->request('propose');self::assertIsArray($p);self::assertSame('PENDING',$p['local_review_status']);
            self::assertSame('10.0000',$p['allocation']['allocated_amount']);self::assertArrayNotHasKey('allocation_packet',$p);self::assertStringNotContainsString('offline/statement-7',wp_json_encode($p));
            self::assertWPError($this->request('localreview',$p));wp_set_current_user($this->actors['reviewer']);
            $a=$this->request('localreview',$p);self::assertIsArray($a);self::assertSame('REVIEWED_LOCAL_ALLOCATION',$a['local_review_status']);
            self::assertSame($a,$this->request());self::assertSame('UNKNOWN',$a['authenticity']);
            foreach(['period_approval_authorized','external_execution_authorized','money_movement_authorized','tax_filing_authorized','actual_settlement_verified'] as $field)self::assertFalse($a[$field]);
            $r=$this->request('revoke',$a);self::assertIsArray($r);self::assertSame('REVOKED',$r['local_review_status']);self::assertNull($r['allocation']);
            self::assertWPError($this->request('localreview',$p));self::assertTrue(DigiForge\Core\Settings::safety_locked());
        }finally{remove_filter('pre_http_request',$http);}self::assertSame(0,$calls);
    }
    public function testReadAndProposalNeedFinanceWhileDecisionsAlsoNeedSettings():void
    {
        $this->loadWorkflow();$author=wp_get_current_user();$author->add_cap('manage_digiforge_settings',false);
        self::assertNull($this->request());$p=$this->request('propose');self::assertIsArray($p);
        wp_set_current_user($this->actors['reviewer']);$reviewer=wp_get_current_user();$reviewer->add_cap('manage_digiforge_settings',false);
        self::assertIsArray($this->request());self::assertWPError($this->request('localreview',$p));self::assertWPError($this->request('reject',$p));self::assertWPError($this->request('revoke',$p));
        $reviewer->add_cap('manage_digiforge_settings',true);$r=$this->request('reject',$p);self::assertIsArray($r);self::assertSame('REJECTED',$r['local_review_status']);self::assertNull($r['allocation']);
    }
    public function testNonceMalformedScopeUnexpectedAndUnboundedPayloadsDenyBeforeSourceRead():void
    {
        $this->loadWorkflow();$reads=0;$table=Tables::finance_ledger();$capture=static function(string $sql)use(&$reads,$table):string{if(str_starts_with($sql,'SELECT * FROM '.$table.' WHERE environment='))$reads++;return $sql;};add_filter('query',$capture);
        try{foreach([['_wpnonce'=>'invalid'],['shop_key'=>'digital'],['lines_json'=>'{'],['lines_json'=>str_repeat(' ',32769)],['business_id'=>'01'],['unexpected'=>'private']] as $change)self::assertWPError(FinanceAllocationReviewWorkflow::processBrowser(array_replace($this->post('propose'),$change),'personalized_pod'));}
        finally{remove_filter('query',$capture);}self::assertSame(0,$reads);
    }
    public function testPartialOrAnonymousFinanceAccessCannotReadLocalState():void
    {
        $this->loadWorkflow();$post=$this->post();ShopAccessFixture::reset();ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge','manage_digiforge_finance']);
        self::assertWPError(FinanceAllocationReviewWorkflow::processBrowser($post,'personalized_pod'));wp_set_current_user(0);self::assertWPError(FinanceAllocationReviewWorkflow::processBrowser($post,'personalized_pod'));
    }
    public function testTargetDriftHidesAmountsButKeepsRecoveryMetadata():void
    {
        $this->loadWorkflow();$p=$this->request('propose');self::assertIsArray($p);wp_set_current_user($this->actors['reviewer']);self::assertIsArray($this->request('localreview',$p));global $wpdb;
        self::assertSame(1,$wpdb->update(Tables::order_line_items(),['unit_price_amount'=>'1.0000'],['id'=>$this->fixture['target']['order_line_item_id']]));
        $stale=$this->request();self::assertIsArray($stale);self::assertSame('STALE',$stale['local_review_status']);self::assertNull($stale['allocation']);self::assertSame(2,$stale['review']['revision']);
    }
    public function testRenderingClearsRawInputsAndRequiresExactSelectedQueryShop():void
    {
        $this->loadWorkflow();$post=$this->post('propose');$post['lines_json']='{"private":"NEVER_ECHO_ALLOCATION_REVIEW_RAW"}';
        $html=$this->renderPost($post);self::assertStringContainsString('BLOCKED',$html);self::assertStringNotContainsString('NEVER_ECHO_ALLOCATION_REVIEW_RAW',$html);
        self::assertStringContainsString('CONFIRM_LOCAL_ALLOCATION',$html);self::assertStringContainsString('Money movement authority: NO',$html);self::assertStringNotContainsString('offline/statement-7',$html);
        $html=$this->renderPost($this->post('propose'),'digital');self::assertStringContainsString('BLOCKED',$html);self::assertStringNotContainsString('data-finance-allocation-review-result',$html);
        $html=$this->renderPost($this->post('propose'));self::assertStringContainsString('data-finance-allocation-review-result',$html);self::assertStringContainsString('PENDING',$html);self::assertStringContainsString('10.0000',$html);
        self::assertStringNotContainsString($this->post('propose')['lines_json'],$html);self::assertStringNotContainsString('name="source_hash" value=',$html);
    }
    private function renderPost(array $post,string $queryShop='personalized_pod'):string
    {
        $oldPost=$_POST;$oldGet=$_GET;$server=$_SERVER;$level=ob_get_level();
        try{$_POST=$post;$_GET=['df_view'=>'finance','df_shop'=>$queryShop];$_SERVER['REQUEST_METHOD']='POST';$_SERVER['CONTENT_LENGTH']=(string)strlen(http_build_query($post));ob_start();FinanceAllocationReviewWorkflow::render('personalized_pod');return ob_get_clean();}
        finally{$_POST=$oldPost;$_GET=$oldGet;$_SERVER=$server;while(ob_get_level()>$level)ob_end_clean();}
    }
}
