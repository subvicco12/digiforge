<?php
declare(strict_types=1);
use DigiForge\Database\Tables;
use DigiForge\Finance\DeclarationContract;
use DigiForge\Finance\Repository;
use DigiForge\Portal\FinanceAllocationReviewInput;
use DigiForge\Portal\FinanceAllocationReviewWorkflow;
use DigiForge\Portal\FinanceAllocationProfitabilityPanel;
use DigiForge\Security\ShopAccessPolicy;
require_once __DIR__.'/FinanceAllocationReviewTest.php';

/** Synthetic local journeys only; no provider call or financial-period approval. */
final class FinanceAllocationReviewReversalPortalTest extends WP_UnitTestCase
{
    private array $actors=[];
    private array $fixture=[];
    private string $temporary='';
    private ?Closure $remap=null;
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
        $this->actors=ReviewedLocalFixture::actors('personalized_pod');
        foreach(['author','reviewer'] as $actor)ShopAccessFixture::authorizeActor($this->actors[$actor],ShopAccessPolicy::canonicalShops(),['manage_digiforge','manage_digiforge_finance']);
        wp_set_current_user($this->actors['author']);global $wpdb;$table=Tables::finance_ledger();
        $this->temporary=$table.'_reversal_portal_'.substr(str_replace('-','',wp_generate_uuid4()),0,8);self::assertNotFalse($wpdb->query('CREATE TEMPORARY TABLE '.$this->temporary.' LIKE '.$table));$temporary=$this->temporary;
        $this->remap=static fn(string $sql):string=>str_replace($table,$temporary,$sql);add_filter('query',$this->remap,99);
        $method=new ReflectionMethod(FinanceAllocationReviewTest::class,'fixture');$this->fixture=$method->invoke(new FinanceAllocationReviewTest('testSeparateReviewPersistsExactPacketWithoutFinancialAuthority'));
        $this->fixture['scope']=array_intersect_key($this->fixture['target'],array_flip(['shop_key','business_id','store_id','environment']));
        $this->fixture['input']=$this->fixture['source'];unset($this->fixture['input']['declaration_hash'],$this->fixture['input']['accounting_convention'],$this->fixture['input']['authenticity']);
        self::assertIsArray((new Repository())->importDeclaration($this->fixture['input']));
    }
    protected function tearDown():void
    {
        if($this->remap!==null)remove_filter('query',$this->remap,99);global $wpdb;
        if($this->temporary!=='')$wpdb->query('DROP TEMPORARY TABLE IF EXISTS '.$this->temporary);parent::tearDown();
    }
    private function post(string $hash,string $operation='view',?array $state=null):array
    {
        $post=['action'=>FinanceAllocationReviewInput::ACTION,'_wpnonce'=>wp_create_nonce(FinanceAllocationReviewInput::ACTION),'operation'=>$operation,'shop_key'=>'personalized_pod','environment'=>'test','business_id'=>(string)$this->fixture['scope']['business_id'],'store_id'=>(string)$this->fixture['scope']['store_id'],'source_hash'=>$hash];
        if($operation==='propose')$post+=['lines_json'=>wp_json_encode([['target'=>$this->fixture['target'],'amount'=>'10','base_amount'=>'10']]),'effective_at'=>(string)(time()-1),'expires_at'=>(string)(time()+600),'expected_revision'=>'0','request_id'=>'reversal_portal_'.wp_generate_uuid4()];
        elseif($operation!=='view')$post+=['expected_revision'=>(string)$state['review']['revision'],'proposal_hash'=>$state['review']['proposal_hash'],'request_id'=>'reversal_portal_'.wp_generate_uuid4()];
        return $post;
    }
    private function request(array $post):array|WP_Error|null {return FinanceAllocationReviewWorkflow::processBrowser($post,'personalized_pod');}
    private static function reference(array $state):array
    {
        return ['source_hash'=>$state['source_hash'],'revision'=>$state['review']['revision'],'state_hash'=>$state['review']['state_hash'],'proposal_hash'=>$state['review']['proposal_hash']];
    }
    private function original():array
    {
        wp_set_current_user($this->actors['author']);$hash=$this->fixture['source']['declaration_hash'];
        $p=$this->request($this->post($hash,'propose'));self::assertIsArray($p);self::assertArrayNotHasKey('original_review_reference',$p);
        wp_set_current_user($this->actors['reviewer']);$a=$this->request($this->post($hash,'localreview',$p));self::assertIsArray($a);return $a;
    }
    private function reversal(array $original,bool $later=false):array
    {
        $input=array_replace($this->fixture['input'],['external_transaction_id'=>'synthetic-reversal-'.wp_generate_uuid4(),'reversal_of_hash'=>$original['source_hash'],'amount'=>'-10','base_amount'=>'-10']);
        if($later)$input=array_replace($input,['occurred_at'=>'2026-11-10T12:00:00Z','observed_at'=>'2026-11-10T12:01:00Z']);
        self::assertIsArray((new Repository())->importDeclaration($input));$hash=DeclarationContract::normalize($input)['declaration_hash'];
        wp_set_current_user($this->actors['author']);$post=$this->post($hash,'propose');
        $post['lines_json']=wp_json_encode([['target'=>$this->fixture['target'],'amount'=>'-10','base_amount'=>'-10']]);
        $post['original_review_reference_json']=wp_json_encode(self::reference($original));return $post;
    }
    private function profitPost(array $refs,bool $later=false):array
    {
        return ['action'=>FinanceAllocationProfitabilityPanel::ACTION,'_wpnonce'=>wp_create_nonce(FinanceAllocationProfitabilityPanel::ACTION),'shop_key'=>'personalized_pod','environment'=>'test','business_id'=>(string)$this->fixture['scope']['business_id'],'store_id'=>(string)$this->fixture['scope']['store_id'],'period_start'=>$later?'2026-11-01':'2026-10-01','period_end'=>$later?'2026-11-30':'2026-10-31','base_currency'=>'USD','references_json'=>wp_json_encode($refs)];
    }
    public function testExactInverseSamePageReviewAndPairedProfitabilityRemainRedactedOfflineAndNonAuthoritative():void
    {
        $calls=0;$http=static function($pre)use(&$calls){$calls++;return new WP_Error('offline','No external requests.');};add_filter('pre_http_request',$http);
        try{
            $original=$this->original();$post=$this->reversal($original);$p=$this->request($post);self::assertIsArray($p);self::assertSame('PENDING',$p['local_review_status']);
            self::assertSame(self::reference($original),$p['original_review_reference']);self::assertSame('-10.0000',$p['allocation']['allocated_amount']);
            self::assertWPError($this->request($this->post($post['source_hash'],'localreview',$p)));
            wp_set_current_user($this->actors['reviewer']);$review=$this->request($this->post($post['source_hash'],'localreview',$p));self::assertIsArray($review);self::assertSame('REVIEWED_LOCAL_ALLOCATION',$review['local_review_status']);
            $html=$this->render($this->post($post['source_hash']));self::assertStringContainsString('data-finance-allocation-original-review-reference',$html);self::assertStringContainsString($original['review']['state_hash'],$html);self::assertStringContainsString('-10.0000',$html);self::assertStringNotContainsString('Persistent reversal allocations are unsupported',$html);
            $stale=$this->request($this->post($original['source_hash']));self::assertIsArray($stale);self::assertSame('STALE',$stale['local_review_status']);self::assertNull($stale['allocation']);
            $writes=[];$capture=static function(string $sql)use(&$writes):string{if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i',$sql))$writes[]=$sql;return $sql;};add_filter('query',$capture);
            try{$report=FinanceAllocationProfitabilityPanel::processBrowser($this->profitPost([self::reference($original),self::reference($review)]),'personalized_pod');}finally{remove_filter('query',$capture);}
            self::assertIsArray($report);self::assertSame('0.0000',$report['totals']['gross_revenue']);self::assertSame('0.0000',$report['totals']['net_profit']);self::assertNull($report['totals']['margin_percent']);self::assertSame(2,$report['coverage']['source_count']);self::assertSame([],$writes);
            foreach([$p,$review,$report] as $result){self::assertSame('UNKNOWN',$result['authenticity']);self::assertSame('UNKNOWN',$result['source_completeness']);foreach(['actual_settlement_verified','period_approval_authorized','external_execution_authorized','money_movement_authorized','tax_filing_authorized'] as $flag)self::assertFalse($result[$flag]);self::assertArrayNotHasKey('allocation_packet',$result);self::assertStringNotContainsString('offline/statement-7',wp_json_encode($result));}
            self::assertTrue(DigiForge\Core\Settings::safety_locked());
        }finally{remove_filter('pre_http_request',$http);}self::assertSame(0,$calls);
    }
    public function testChangedPinMissingRetainedReviewAndNonInverseProposalFailClosed():void
    {
        $original=$this->original();$post=$this->reversal($original);
        $changed=self::reference($original);$changed['revision']++;
        self::assertWPError($this->request(array_replace($post,['original_review_reference_json'=>wp_json_encode($changed)])));
        $missing=$post;unset($missing['original_review_reference_json']);self::assertWPError($this->request($missing));
        self::assertWPError($this->request(array_replace($post,['lines_json'=>wp_json_encode([['target'=>$this->fixture['target'],'amount'=>'-9','base_amount'=>'-9']])])));
        self::assertWPError($this->request($post+['original_packet'=>['secret'=>'PRIVATE_ORIGINAL_PACKET']]));
        $p=$this->request($post);self::assertIsArray($p);wp_set_current_user($this->actors['reviewer']);wp_get_current_user()->add_cap('manage_digiforge_settings',false);
        self::assertWPError($this->request($this->post($post['source_hash'],'localreview',$p)));
        $html=$this->render(array_replace($post,['original_review_reference_json'=>'{"private":"PRIVATE_ORIGINAL_PACKET"}']));self::assertStringContainsString('BLOCKED',$html);self::assertStringNotContainsString('PRIVATE_ORIGINAL_PACKET',$html);
        self::assertStringNotContainsString('name="original_review_reference_json" value=',$html);
    }
    public function testLaterPeriodReversalOnlyIsAllowedButStandaloneHistoricalOriginalAndChangedPinsAreBlocked():void
    {
        $original=$this->original();$post=$this->reversal($original,true);$p=$this->request($post);self::assertIsArray($p);wp_set_current_user($this->actors['reviewer']);
        $review=$this->request($this->post($post['source_hash'],'localreview',$p));self::assertIsArray($review);
        $report=FinanceAllocationProfitabilityPanel::processBrowser($this->profitPost([self::reference($review)],true),'personalized_pod');self::assertIsArray($report);self::assertSame('-10.0000',$report['totals']['gross_revenue']);self::assertSame(1,$report['coverage']['source_count']);self::assertFalse($report['period_approval_authorized']);
        self::assertWPError(FinanceAllocationProfitabilityPanel::processBrowser($this->profitPost([self::reference($original)]),'personalized_pod'));
        $changed=self::reference($review);$changed['state_hash']=str_repeat('f',64);$error=FinanceAllocationProfitabilityPanel::processBrowser($this->profitPost([$changed],true),'personalized_pod');self::assertWPError($error);self::assertNull($error->get_error_data()['totals']);
    }
    public function testOptionalTransportStillEnforcesNonceQueryBodyAndCompleteFinanceAssignments():void
    {
        $original=$this->original();$post=$this->reversal($original);$reads=0;$table=Tables::finance_ledger();
        $capture=static function(string $sql)use(&$reads,$table):string{if(str_starts_with($sql,'SELECT * FROM '.$table.' WHERE environment='))$reads++;return $sql;};add_filter('query',$capture);
        try{foreach([['_wpnonce'=>'invalid'],['original_review_reference_json'=>str_repeat(' ',1025)],['original_review_reference_json'=>wp_json_encode(self::reference($original)+['original_packet'=>[]])]] as $change)self::assertWPError($this->request(array_replace($post,$change)));}finally{remove_filter('query',$capture);}self::assertSame(0,$reads);
        foreach([['digital','500'],['personalized_pod','65537']] as [$shop,$length]){ $html=$this->render($post,$shop,$length);self::assertStringContainsString('BLOCKED',$html);self::assertStringNotContainsString('data-finance-allocation-review-result',$html);}
        ShopAccessFixture::reset();ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge','manage_digiforge_finance']);self::assertWPError($this->request($post));
    }
    private function render(array $post,string $queryShop='personalized_pod',?string $length=null):string
    {
        $oldPost=$_POST;$oldGet=$_GET;$server=$_SERVER;$level=ob_get_level();
        try{$_POST=$post;$_GET=['df_view'=>'finance','df_shop'=>$queryShop];$_SERVER['REQUEST_METHOD']='POST';$_SERVER['CONTENT_LENGTH']=$length??(string)strlen(http_build_query($post));ob_start();FinanceAllocationReviewWorkflow::render('personalized_pod');return ob_get_clean();}
        finally{$_POST=$oldPost;$_GET=$oldGet;$_SERVER=$server;while(ob_get_level()>$level)ob_end_clean();}
    }
}
