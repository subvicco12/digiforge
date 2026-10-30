<?php
declare(strict_types=1);

use DigiForge\Finance\EtsyFinancialSourceParser;
use DigiForge\Portal\FinanceNativeSourceWorkflow;
use DigiForge\Security\ShopAccessPolicy;
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once dirname(__DIR__).'/unit/FinanceDeclarationFixtures.php';

/** Synthetic original JSON only; no provider/credential access or accounting writes. */
final class FinanceNativeSourceWorkflowTest extends WP_UnitTestCase
{
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();ShopAccessFixture::reset();DigiForge\Core\Settings::protectProduction();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        ShopAccessFixture::authorizeActor(get_current_user_id(),ShopAccessPolicy::canonicalShops(),['manage_digiforge','manage_digiforge_finance']);
    }
    private function payload(string $shop='digital'):array
    {
        $scope=['shop_key'=>$shop,'environment'=>'test','business_id'=>1,'store_id'=>2,'external_shop_id'=>777];
        $raw='{"payment_id":11,"shop_id":777,"status":"PRIVATE_CUSTOMER_MARKER"}';
        return ['action'=>FinanceNativeSourceWorkflow::ACTION,'shop_key'=>$shop,'_wpnonce'=>wp_create_nonce(FinanceNativeSourceWorkflow::ACTION),
            'context_json'=>wp_json_encode($scope+['external_account_id'=>778,'integration_id'=>3,'source_contract_sha256'=>EtsyFinancialSourceParser::CONTRACT_SHA256]),
            'documents_json'=>wp_json_encode([['native_schema'=>'Payment','raw'=>$raw,'capture'=>$scope+['observed_at'=>'2026-10-19T12:00:00Z','source_reference'=>'local://synthetic/source','source_sha256'=>hash('sha256',$raw)]]])];
    }
    public function testBothActiveShopsHaveNonpersistingOfflineValidationUnderSafetyLocks():void
    {
        global $wpdb;$writes=[];$http=0;
        $query=static function(string $sql)use(&$writes):string{if(preg_match('/^(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i',ltrim($sql)))$writes[]=$sql;return $sql;};
        $network=static function($pre)use(&$http){++$http;return new WP_Error('offline_http_forbidden');};
        // Build nonce before observing writes; the staging action itself must perform none.
        $posts=[$this->payload('digital'),$this->payload('personalized_pod')];add_filter('query',$query);add_filter('pre_http_request',$network,PHP_INT_MAX);
        try{foreach($posts as$post){$out=FinanceNativeSourceWorkflow::processBrowser($post,$post['shop_key']);self::assertIsArray($out);self::assertFalse($out['persistence_performed']);self::assertSame('UNKNOWN',$out['ownership']);self::assertFalse($out['external_execution_authorized']);}}
        finally{remove_filter('query',$query);remove_filter('pre_http_request',$network,PHP_INT_MAX);}
        self::assertSame([],$writes);self::assertSame(0,$http);
    }
    public function testAuthenticationAllFourAssignmentsAndRevocationRemainMandatory():void
    {
        $post=$this->payload();$actor=get_current_user_id();ShopAccessFixture::reset();
        ShopAccessFixture::authorizeActor($actor,['digital'],['manage_digiforge','manage_digiforge_finance']);
        self::assertWPError(FinanceNativeSourceWorkflow::processBrowser($post,'digital'));
        ShopAccessFixture::reset();self::assertWPError(FinanceNativeSourceWorkflow::processBrowser($post,'digital'));
        wp_set_current_user(0);$out=FinanceNativeSourceWorkflow::processBrowser($post,'digital');self::assertWPError($out);self::assertSame(401,$out->get_error_data()['status']);
    }
    public function testForeignShopDeferredShopNonceAndInvalidSourcesFailClosed():void
    {
        foreach([['_wpnonce'=>'invalid'],['shop_key'=>'personalized_pod'],['documents_json'=>'{'],['context_json'=>'{}'],['documents_json'=>str_repeat(' ',1048577)],['approved'=>'yes']]as$change)self::assertWPError(FinanceNativeSourceWorkflow::processBrowser(array_replace($this->payload(),$change),'digital'));
        foreach(['standard_pod','jewelry']as$shop)self::assertWPError(FinanceNativeSourceWorkflow::processBrowser($this->payload($shop),$shop));
        $post=$this->payload();$context=json_decode($post['context_json'],true);$context['shop_key']='personalized_pod';$post['context_json']=wp_json_encode($context);
        self::assertWPError(FinanceNativeSourceWorkflow::processBrowser($post,'digital'));
    }
    public function testRedactedKeyboardTableAndClearedInputsDoNotEchoPrivateText():void
    {
        $post=$_POST;$server=$_SERVER;
        try{$_POST=wp_slash($this->payload());$_SERVER['REQUEST_METHOD']='POST';$_SERVER['CONTENT_LENGTH']='2000';ob_start();FinanceNativeSourceWorkflow::render('digital');$html=ob_get_clean();}
        finally{$_POST=$post;$_SERVER=$server;}
        self::assertStringNotContainsString('PRIVATE_CUSTOMER_MARKER',$html);self::assertStringNotContainsString('"payment_id":11',$html);
        self::assertStringContainsString('tabindex="0"',$html);self::assertStringContainsString('class="df-table-wrap"',$html);
        self::assertStringContainsString('SCHEMA_VALIDATED_UNVERIFIED_SOURCE',$html);self::assertStringContainsString('name="documents_json" rows="8" maxlength="1048576" required></textarea>',$html);
        self::assertStringContainsString('No durable replay claim',$html);
    }
    public function testDuplicateAndConflictErrorsWithholdRawSourceAndNativeDiagnostics():void
    {
        $post=$this->payload();$docs=json_decode($post['documents_json'],true);$post['documents_json']=wp_json_encode([$docs[0],$docs[0]]);
        $out=FinanceNativeSourceWorkflow::processBrowser($post,'digital');self::assertWPError($out);
        self::assertStringNotContainsString('PRIVATE_CUSTOMER_MARKER',wp_json_encode($out));
    }
 /** @dataProvider unsupportedProviders */
    public function testUnsupportedProviderCannotBeParsedAsEtsy(string $provider):void {
        $post=$this->payload();$post['provider']=$provider;
        $r=FinanceNativeSourceWorkflow::processBrowser($post,'digital');self::assertWPError($r);
        self::assertSame('finance_source_contract_missing',$r->get_error_code());
        self::assertFalse($r->get_error_data()['accounting_import_authorized']);
    }
    public static function unsupportedProviders():array{return [['printify'],['gelato'],['openai']];}
    public function testExplicitEtsySelectionRetainsExactLegacyProjection():void {
        $post=$this->payload();$legacy=FinanceNativeSourceWorkflow::processBrowser($post,'digital');$post['provider']='etsy';
        $explicit=FinanceNativeSourceWorkflow::processBrowser($post,'digital');self::assertIsArray($legacy);self::assertIsArray($explicit);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$explicit['stage_hash']);
        // Each preview hashes its own observation second; provider selection changes no source fields.
        unset($legacy['stage_hash'],$explicit['stage_hash']);self::assertSame($legacy,$explicit);
    }
    private function csvPayload(string $shop='digital'):array
    {
        $post=$this->payload($shop);$old=json_decode($post['context_json'],true);
        $csv=DigiForge\Tests\FinanceDeclarationFixtures::csvInspection($old);
        $post['source_format']='csv_structure';$post['provider']='printify';$post['context_json']=wp_json_encode($csv['context']);$post['documents_json']=wp_json_encode($csv['documents']);
        return $post;
    }
    public function testCsvInspectionForDigitalAndPodNeverCreatesDeclarationsOrProviderCalls():void
    {
        global $wpdb;$before=$wpdb->get_results('SELECT * FROM '.DigiForge\Database\Tables::finance_ledger(),ARRAY_A);$http=0;
        $network=static function($pre)use(&$http){++$http;return new WP_Error('csv_external_forbidden');};add_filter('pre_http_request',$network,PHP_INT_MAX);
        try{foreach(['digital','personalized_pod']as$shop){$result=FinanceNativeSourceWorkflow::processBrowser($this->csvPayload($shop),$shop);self::assertIsArray($result);
            self::assertSame('STRUCTURE_VALID_PROVIDER_FORMAT_BLOCKED',$result['state']);self::assertSame('DECLARED_COUNTS_MATCH',$result['population']['state']);
            self::assertSame('UNKNOWN',$result['provider_schema']);self::assertFalse($result['accounting_import_authorized']);self::assertFalse($result['period_approval_authorized']);
            self::assertWPError((new DigiForge\Finance\Repository())->importDeclaration($result));}}
        finally{remove_filter('pre_http_request',$network,PHP_INT_MAX);}
        self::assertSame(0,$http);self::assertSame($before,$wpdb->get_results('SELECT * FROM '.DigiForge\Database\Tables::finance_ledger(),ARRAY_A));
    }
    public function testCsvPortalUsesStructureOnlyLanguageClearsInputsAndWithholdsCells():void
    {
        $post=$_POST;$server=$_SERVER;
        try{$_POST=wp_slash($this->csvPayload());$_SERVER['REQUEST_METHOD']='POST';$_SERVER['CONTENT_LENGTH']='2000';ob_start();FinanceNativeSourceWorkflow::render('digital');$html=ob_get_clean();}
        finally{$_POST=$post;$_SERVER=$server;}
        self::assertStringContainsString('STRUCTURE_VALID_PROVIDER_FORMAT_BLOCKED',$html);self::assertStringContainsString('Declared row counts do not prove source completeness',$html);
        self::assertStringNotContainsString('SCHEMA_VALIDATED_UNVERIFIED_SOURCE',$html);self::assertStringNotContainsString('0.00100',$html);self::assertStringNotContainsString('csv-marker',$html);
        self::assertStringContainsString('name="documents_json" rows="8" maxlength="1048576" required></textarea>',$html);
    }
    public function testCsvInspectionRejectsForeignCaptureRevokedAndDeferredAccess():void
    {
        $post=$this->csvPayload();$docs=json_decode($post['documents_json'],true);$docs[0]['capture']['shop_key']='personalized_pod';$post['documents_json']=wp_json_encode($docs);
        self::assertWPError(FinanceNativeSourceWorkflow::processBrowser($post,'digital'));
        self::assertWPError(FinanceNativeSourceWorkflow::processBrowser($this->csvPayload('jewelry'),'jewelry'));
        ShopAccessFixture::reset();self::assertWPError(FinanceNativeSourceWorkflow::processBrowser($this->csvPayload(),'digital'));
    }

    public function testCsvReturnRechecksActorLossDuringFinalAllFourShopRead():void
    {
        $post=$this->csvPayload();$changed=false;$reads=0;
        $filter=static function(string $sql)use(&$changed,&$reads):string{
            if(str_starts_with($sql,'SELECT option_value FROM ')&&str_contains($sql,"'digiforge_shop_access_state_digital'")&&++$reads===2){$changed=true;wp_set_current_user(0);}return $sql;
        };
        add_filter('query',$filter);try{$result=FinanceNativeSourceWorkflow::processBrowser($post,'digital');}finally{remove_filter('query',$filter);}
        self::assertTrue($changed);self::assertGreaterThanOrEqual(2,$reads);self::assertWPError($result);self::assertSame(403,$result->get_error_data()['status']);
    }

    public function testCsvInspectionRequiresExplicitProviderRatherThanLegacyEtsyDefault():void
    {
        $post=$this->csvPayload();unset($post['provider']);
        self::assertWPError(FinanceNativeSourceWorkflow::processBrowser($post,'digital'));
    }

}
