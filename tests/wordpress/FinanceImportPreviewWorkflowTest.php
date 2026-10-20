<?php
declare(strict_types=1);
use DigiForge\Database\Tables;
use DigiForge\Portal\FinanceImportPreviewInput;
use DigiForge\Portal\FinanceImportPreviewWorkflow;
require_once __DIR__.'/FinanceAllocationStoredTargetReferencesTest.php';

/** Synthetic stored declarations only. Every preview remains unreviewed and nonpersisting. */
final class FinanceImportPreviewWorkflowTest extends WP_UnitTestCase
{
    private array $fixture=[];
    private string $temporary='';
    private ?Closure $remap=null;
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();global $wpdb;
        $table=Tables::finance_ledger();$this->temporary=$table.'_preview_'.substr(str_replace('-','',wp_generate_uuid4()),0,8);
        self::assertNotFalse($wpdb->query('CREATE TEMPORARY TABLE '.$this->temporary.' LIKE '.$table));$temporary=$this->temporary;
        $this->remap=static fn(string $sql):string=>str_replace($table,$temporary,$sql);add_filter('query',$this->remap,99);
        $method=new ReflectionMethod(FinanceAllocationStoredTargetReferencesTest::class,'persistedFixture');$method->setAccessible(true);
        $this->fixture=$method->invoke(new FinanceAllocationStoredTargetReferencesTest());
        ShopAccessFixture::authorizeActor(get_current_user_id(),DigiForge\Security\ShopAccessPolicy::canonicalShops(),['manage_digiforge','manage_digiforge_finance']);
    }
    protected function tearDown():void
    {
        if($this->remap!==null)remove_filter('query',$this->remap,99);global $wpdb;
        if($this->temporary!=='')$wpdb->query('DROP TEMPORARY TABLE IF EXISTS '.$this->temporary);parent::tearDown();
    }
    private function loadWorkflow():void {self::assertFileExists(dirname(__DIR__,2).'/includes/Portal/FinanceImportPreviewWorkflow.php');}
    private function payload():array
    {
        return ['action'=>FinanceImportPreviewInput::ACTION,'_wpnonce'=>wp_create_nonce(FinanceImportPreviewInput::ACTION),'shop_key'=>'personalized_pod','environment'=>'test',
            'business_id'=>(string)$this->fixture['scope']['business_id'],'store_id'=>(string)$this->fixture['scope']['store_id'],
            'source_hash'=>$this->fixture['source']['declaration_hash'],'lines_json'=>wp_json_encode([['target'=>$this->fixture['target'],'amount'=>'10.0000','base_amount'=>'10.0000']]),'original_packet_json'=>''];
    }
    public function testCurrentStoredPreviewIsExactRedactedAndDoesNotWriteOrCallNetwork():void
    {
        $this->loadWorkflow();$post=$this->payload();$writes=[];$calls=0;
        $capture=static function(string $sql)use(&$writes):string{if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i',$sql))$writes[]=$sql;return $sql;};
        $http=static function($pre)use(&$calls){$calls++;return new WP_Error('offline_preview','External requests forbidden.');};add_filter('query',$capture);add_filter('pre_http_request',$http);
        try{
            $result=FinanceImportPreviewWorkflow::processBrowser($post,'personalized_pod');self::assertIsArray($result);self::assertSame('10.0000',$result['allocated_amount']);self::assertSame('PROVISIONAL',$result['state']);self::assertArrayNotHasKey('source',$result);self::assertStringNotContainsString('offline/statement-7',wp_json_encode($result));self::assertFalse($result['persistence_performed']);
            $oldPost=$_POST;$server=$_SERVER;$level=ob_get_level();
            try{$_POST=$post;$_SERVER['REQUEST_METHOD']='POST';$_SERVER['CONTENT_LENGTH']=(string)strlen(http_build_query($post));ob_start();FinanceImportPreviewWorkflow::render('personalized_pod');$html=ob_get_clean();}
            finally{$_POST=$oldPost;$_SERVER=$server;while(ob_get_level()>$level)ob_end_clean();}
            self::assertStringContainsString('data-finance-allocation-result',$html);self::assertStringContainsString('10.0000',$html);self::assertStringContainsString('Explicit allocation preview lines',$html);self::assertStringNotContainsString('offline/statement-7',$html);self::assertStringNotContainsString($post['lines_json'],$html);
        }
        finally{remove_filter('query',$capture);remove_filter('pre_http_request',$http);}
        self::assertSame([],$writes);self::assertSame(0,$calls);self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }
    public function testNonceMalformedScopeAndOversizeBodiesDenyBeforeSourceReads():void
    {
        $this->loadWorkflow();global $wpdb;$reads=0;$table=Tables::finance_ledger();$capture=static function(string $sql)use(&$reads,$table):string{if(str_starts_with($sql,'SELECT * FROM '.$table.' WHERE environment='))$reads++;return $sql;};add_filter('query',$capture);
        try{foreach([['_wpnonce'=>'invalid'],['shop_key'=>'digital'],['lines_json'=>'{'],['lines_json'=>str_repeat(' ',65537)],['business_id'=>'01']] as $change)self::assertWPError(FinanceImportPreviewWorkflow::processBrowser(array_replace($this->payload(),$change),'personalized_pod'));}
        finally{remove_filter('query',$capture);}self::assertSame(0,$reads);
    }
    public function testPartialAndAnonymousFinanceAccessCannotReadSource():void
    {
        $this->loadWorkflow();$post=$this->payload();ShopAccessFixture::reset();ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge','manage_digiforge_finance']);
        self::assertWPError(FinanceImportPreviewWorkflow::processBrowser($post,'personalized_pod'));wp_set_current_user(0);self::assertWPError(FinanceImportPreviewWorkflow::processBrowser($post,'personalized_pod'));
    }
    public function testInlineResponseDoesNotEchoRawPostOrPrivateErrors():void
    {
        $this->loadWorkflow();$post=$_POST;$server=$_SERVER;
        try{$_POST=$this->payload();$_POST['lines_json']='{"private":"NEVER_ECHO_PREVIEW_RAW"}';$_SERVER['REQUEST_METHOD']='POST';$_SERVER['CONTENT_LENGTH']='500';ob_start();FinanceImportPreviewWorkflow::render('personalized_pod');$html=ob_get_clean();}
        finally{$_POST=$post;$_SERVER=$server;}
        self::assertStringContainsString('BLOCKED',$html);self::assertStringNotContainsString('NEVER_ECHO_PREVIEW_RAW',$html);self::assertStringContainsString('Money movement authority: NO',$html);self::assertStringNotContainsString('name="lines_json">{',$html);
    }
}
