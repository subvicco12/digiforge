<?php
declare(strict_types=1);
use DigiForge\Database\Tables;
use DigiForge\Finance\AllocationReview;
use DigiForge\Portal\FinanceAllocationProfitabilityPanel;
use DigiForge\Security\ShopAccessPolicy;
require_once __DIR__.'/FinanceAllocationReviewTest.php';

/** Explicit synthetic subset only, with no provider access or financial authority. */
final class FinanceAllocationProfitabilityPortalTest extends WP_UnitTestCase
{
    private array $actors=[];
    private array $fixture=[];
    private ?Closure $remap=null;
    private string $temporary='';
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
        $this->actors=ReviewedLocalFixture::actors('personalized_pod');
        foreach(['author','reviewer'] as $actor)ShopAccessFixture::authorizeActor($this->actors[$actor],ShopAccessPolicy::canonicalShops(),['manage_digiforge','manage_digiforge_finance']);
        wp_set_current_user($this->actors['author']);global $wpdb;$table=Tables::finance_ledger();
        $this->temporary=$table.'_profit_portal_'.substr(str_replace('-','',wp_generate_uuid4()),0,8);self::assertNotFalse($wpdb->query('CREATE TEMPORARY TABLE '.$this->temporary.' LIKE '.$table));$temporary=$this->temporary;
        $this->remap=static fn(string $sql):string=>str_replace($table,$temporary,$sql);add_filter('query',$this->remap,99);
        $method=new ReflectionMethod(FinanceAllocationReviewTest::class,'fixture');$this->fixture=$method->invoke(new FinanceAllocationReviewTest('testSeparateReviewPersistsExactPacketWithoutFinancialAuthority'));
        $this->fixture['scope']=array_intersect_key($this->fixture['target'],array_flip(['shop_key','business_id','store_id','environment']));
    }
    protected function tearDown():void
    {
        if($this->remap!==null)remove_filter('query',$this->remap,99);global $wpdb;
        if($this->temporary!=='')$wpdb->query('DROP TEMPORARY TABLE IF EXISTS '.$this->temporary);parent::tearDown();
    }
    private function loadPanel():void {self::assertFileExists(dirname(__DIR__,2).'/includes/Portal/FinanceAllocationProfitabilityPanel.php');}
    private function reviewed():array
    {
        $f=$this->fixture;$input=$f['source'];unset($input['declaration_hash'],$input['accounting_convention'],$input['authenticity']);self::assertIsArray((new DigiForge\Finance\Repository())->importDeclaration($input));
        $service=new AllocationReview();$p=$service->propose($f['source']['declaration_hash'],$f['scope'],[['target'=>$f['target'],'amount'=>'10','base_amount'=>'10']],time()-1,time()+600,'profit_portal_propose_1',0);self::assertIsArray($p);
        wp_set_current_user($this->actors['reviewer']);$a=$service->decide($f['source']['declaration_hash'],$f['scope'],$p['review']['revision'],$p['review']['proposal_hash'],'APPROVED','profit_portal_review_1');self::assertIsArray($a);
        return ['source_hash'=>$f['source']['declaration_hash']]+array_intersect_key($a['review'],array_flip(['revision','state_hash','proposal_hash']));
    }
    private function post(?array $ref=null):array
    {
        $ref=$ref??['source_hash'=>str_repeat('a',64),'revision'=>2,'state_hash'=>str_repeat('b',64),'proposal_hash'=>str_repeat('c',64)];
        return ['action'=>'digiforge_finance_allocation_profitability','_wpnonce'=>wp_create_nonce('digiforge_finance_allocation_profitability'),'shop_key'=>'personalized_pod','environment'=>'test','business_id'=>(string)$this->fixture['scope']['business_id'],'store_id'=>(string)$this->fixture['scope']['store_id'],'period_start'=>'2026-10-01','period_end'=>'2026-10-31','base_currency'=>'USD','references_json'=>wp_json_encode([$ref])];
    }
    public function testCurrentExplicitSubsetShowsFixedMetricsAndDimensionsWithoutWritesOrNetwork():void
    {
        $this->loadPanel();$post=$this->post($this->reviewed());$writes=[];$calls=0;
        $capture=static function(string $sql)use(&$writes):string{if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i',$sql))$writes[]=$sql;return $sql;};
        $http=static function($pre)use(&$calls){$calls++;return new WP_Error('offline','No external requests.');};add_filter('query',$capture);add_filter('pre_http_request',$http);
        try{
            $result=FinanceAllocationProfitabilityPanel::processBrowser($post,'personalized_pod');self::assertIsArray($result);self::assertSame('10.0000',$result['totals']['gross_revenue']);self::assertSame('10.0000',$result['totals']['net_profit']);self::assertSame('EXPLICIT_REVIEWED_SUBSET',$result['coverage']['state']);
            self::assertSame(1,$result['coverage']['source_count']);self::assertSame(1,$result['coverage']['line_count']);self::assertArrayNotHasKey('references',$result['coverage']);self::assertArrayNotHasKey('provenance',$result);
            self::assertCount(1,$result['groups']['order']);self::assertArrayNotHasKey('provenance',$result['groups']['order'][0]);
            self::assertStringNotContainsString('offline/statement-7',wp_json_encode($result));self::assertSame('UNKNOWN',$result['authenticity']);self::assertFalse($result['period_approval_authorized']);self::assertFalse($result['coverage']['full_period_coverage_verified']);
            $html=$this->render($post);self::assertStringContainsString('data-finance-allocation-profitability-result',$html);self::assertStringContainsString('EXPLICIT_REVIEWED_SUBSET',$html);self::assertStringContainsString('10.0000',$html);self::assertStringNotContainsString($post['references_json'],$html);self::assertStringNotContainsString('offline/statement-7',$html);
        }finally{remove_filter('query',$capture);remove_filter('pre_http_request',$http);}self::assertSame([],$writes);self::assertSame(0,$calls);self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }
    public function testMalformedExtraNestedOrUnboundedTransportDeniesBeforeSourceRead():void
    {
        $this->loadPanel();$reads=0;$table=Tables::finance_ledger();$capture=static function(string $sql)use(&$reads,$table):string{if(str_starts_with($sql,'SELECT * FROM '.$table.' WHERE environment='))$reads++;return $sql;};add_filter('query',$capture);
        $ref=['source_hash'=>str_repeat('a',64),'revision'=>2,'state_hash'=>str_repeat('b',64),'proposal_hash'=>str_repeat('c',64)];
        try{
            foreach([['_wpnonce'=>'bad'],['shop_key'=>'digital'],['business_id'=>'01'],['store_id'=>[]],['period_start'=>'2026-02-30'],['period_end'=>'2026-1-1'],['period_start'=>'2026-11-01'],['base_currency'=>'usd'],['environment'=>'live'],['references_json'=>'[]'],['references_json'=>'{}'],['references_json'=>'['],['references_json'=>str_repeat(' ',16385)],['references_json'=>wp_json_encode(array_fill(0,21,$ref))],['references_json'=>wp_json_encode([$ref+['private'=>'forbidden']])],['references_json'=>wp_json_encode([array_replace($ref,['revision'=>'2'])])],['references_json'=>wp_json_encode([array_replace($ref,['state_hash'=>[]])])],['unexpected'=>'private']] as $change)self::assertWPError(FinanceAllocationProfitabilityPanel::processBrowser(array_replace($this->post(),$change),'personalized_pod'));
        }finally{remove_filter('query',$capture);}self::assertSame(0,$reads);
    }
    public function testPartialFinanceAndAnonymousDeniedWhileSettingsIsNotRequired():void
    {
        $this->loadPanel();$ref=$this->reviewed();$reader=self::factory()->user->create(['role'=>'administrator']);ShopAccessFixture::authorizeActor($reader,ShopAccessPolicy::canonicalShops(),['manage_digiforge','manage_digiforge_finance']);wp_set_current_user($reader);wp_get_current_user()->add_cap('manage_digiforge_settings',false);$post=$this->post($ref);self::assertIsArray(FinanceAllocationProfitabilityPanel::processBrowser($post,'personalized_pod'));
        ShopAccessFixture::reset();ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge','manage_digiforge_finance']);self::assertWPError(FinanceAllocationProfitabilityPanel::processBrowser($post,'personalized_pod'));
        wp_set_current_user(0);self::assertWPError(FinanceAllocationProfitabilityPanel::processBrowser($post,'personalized_pod'));
    }
    public function testChangedPinsOrTargetsReturnUnknownWithoutFallbackTotals():void
    {
        $this->loadPanel();$ref=$this->reviewed();$post=$this->post(array_replace($ref,['revision'=>99]));$error=FinanceAllocationProfitabilityPanel::processBrowser($post,'personalized_pod');self::assertWPError($error);self::assertNull($error->get_error_data()['totals']);
        global $wpdb;self::assertSame(1,$wpdb->update(Tables::order_line_items(),['unit_price_amount'=>'2.0000'],['id'=>$this->fixture['target']['order_line_item_id']]));$html=$this->render($this->post($ref));
        self::assertStringContainsString('BLOCKED',$html);self::assertStringContainsString('UNKNOWN',$html);self::assertStringNotContainsString('10.0000',$html);self::assertStringNotContainsString('0.0000',$html);
    }
    public function testRenderingRejectsQueryMismatchAndLargeBodyAndClearsInputs():void
    {
        $this->loadPanel();$post=$this->post();$post['references_json']='{"private":"NEVER_ECHO_PROFITABILITY_RAW"}';
        $html=$this->render($post);self::assertStringContainsString('BLOCKED',$html);self::assertStringNotContainsString('NEVER_ECHO_PROFITABILITY_RAW',$html);self::assertStringNotContainsString('name="references_json">{',$html);self::assertStringContainsString('Money movement authority: NO',$html);
        foreach([['digital','500'],['personalized_pod','65537']] as [$shop,$length]){self::assertStringContainsString('BLOCKED',$this->render($this->post(),$shop,$length));}
    }
    private function render(array $post,string $queryShop='personalized_pod',?string $length=null):string
    {
        $oldPost=$_POST;$oldGet=$_GET;$server=$_SERVER;$level=ob_get_level();
        try{$_POST=$post;$_GET=['df_view'=>'finance','df_shop'=>$queryShop];$_SERVER['REQUEST_METHOD']='POST';$_SERVER['CONTENT_LENGTH']=$length??(string)strlen(http_build_query($post));ob_start();FinanceAllocationProfitabilityPanel::render('personalized_pod');return ob_get_clean();}
        finally{$_POST=$oldPost;$_GET=$oldGet;$_SERVER=$server;while(ob_get_level()>$level)ob_end_clean();}
    }
}
