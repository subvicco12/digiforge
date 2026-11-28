<?php
declare(strict_types=1);

require_once __DIR__.'/Support/ReviewedLocalFixture.php';
require_once __DIR__.'/Support/ReviewedOwnershipFixture.php';
require_once __DIR__.'/Support/ScopedProviderConnectionFixture.php';
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/DurableCatalogFixture.php';
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Integrations\ConnectionTester;
use DigiForge\Integrations\Repository;
use DigiForge\POD\PrintifyCatalogClient;
use DigiForge\POD\PrintifyCatalogSyncService;
use DigiForge\POD\PrintifyScopedCredentialRetriever;

/** Synthetic disposable-database consumer acceptance; every HTTP request is intercepted. */
final class PrintifyConsumerSafetyAcceptanceTest extends WP_UnitTestCase
{
    use DurableCatalogFixture { tearDown as private restoreConsumerSnapshot; }
    public static function set_up_before_class():void {parent::set_up_before_class();PrintifyApiApprovalFixture::checked(DigiForge\Database\ProviderConnectionSchema::migrateIfNeeded());}
    private int $integrationId;
    private array $scopes=[];
    private int $httpCalls = 0;
    private const TOKEN = 'synthetic-consumer-safety-token';
    private mixed $httpFilter;

    public function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        $this->snapshotCatalogSession();
        Settings::protectProduction();
        $actors=ReviewedLocalFixture::actors('personalized_pod');$fixture=ReviewedOwnershipFixture::create($this);$this->integrationId=$fixture['integration_id'];
        $repository=new Repository();self::assertTrue($repository->storeSecret($this->integrationId,'personal_access_token',self::TOKEN));self::assertNotWPError($repository->setEnabled($this->integrationId,true));$this->rebind();
        $this->httpFilter = function () {
            ++$this->httpCalls;
            return new WP_Error('blocked_mock_network', 'Synthetic HTTP boundary');
        };
        add_filter('pre_http_request', $this->httpFilter, 1, 3);
    }

    private function rebind():void {
        $repo=new Repository();$i=$repo->find($this->integrationId);if(is_array($i)&&$i['status']!=='CONFIGURED')self::assertNotWPError($repo->update($this->integrationId,['status'=>'CONFIGURED']));
        foreach(['catalog'=>'printify.catalog.read','connection'=>'printify.connection.read']as $key=>$cap){ScopedProviderConnectionFixture::approve($this->integrationId,get_current_user_id(),null,$cap);$scope=DigiForge\POD\PrintifyConnectionBoundary::context('personalized_pod',$this->integrationId,$cap);self::assertNotWPError($scope);$this->scopes[$key]=$scope;}
    }
    private function consume(callable $callback):mixed {return (new PrintifyScopedCredentialRetriever())->consume($this->integrationId,$callback,$this->scopes['catalog']);}

    public function tearDown(): void
    {
        remove_filter('pre_http_request', $this->httpFilter, 1);
        Settings::protectProduction();
        $this->restoreConsumerSnapshot();
    }

    /** Local fixture authority only; never applied to a provider or another database. */
    private function authorizeSyntheticConsumer(): void
    {
        global $wpdb;
        foreach (['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false,
            'printify'=>true,'printify_activation_authorized'=>true] as $key=>$value) {
            $wpdb->update(Tables::settings(), ['setting_value'=>wp_json_encode($value)], ['setting_key'=>$key]);
        }
        self::assertTrue(Settings::is_enabled('printify'));
    }

    private function fault(callable $filter, callable $operation): mixed
    {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $filter);
        try { return $operation(); }
        finally { remove_filter('query', $filter); $wpdb->suppress_errors($previous); }
    }

    public function testUnavailableIntegrationEvidenceRemainsWpErrorForEveryConsumer(): void
    {
        $this->authorizeSyntheticConsumer();
        $filter = static fn(string $sql): string => str_contains($sql, 'SELECT * FROM '.Tables::integrations().' WHERE id =')
            ? 'SELECT * FROM digiforge_missing_consumer_fixture' : $sql;
        $consumerCalls = 0;
        $results = $this->fault($filter, function () use (&$consumerCalls): array {
            return [
                (new ConnectionTester())->test($this->integrationId,$this->scopes['connection']),
                (new PrintifyCatalogSyncService())->syncBlueprint($this->integrationId, 101,$this->scopes['catalog']),
                (new PrintifyCatalogSyncService())->syncProviderVariants($this->integrationId, 101, 2,$this->scopes['catalog']),
                $this->consume(function () use (&$consumerCalls) { ++$consumerCalls; }),
            ];
        });
        foreach ($results as $index => $result) {
            self::assertWPError($result);
            self::assertSame(in_array($index,[0,3],true)?'integration_read_unavailable':'digiforge_printify_governed_catalog_required', $result->get_error_code());
        }
        self::assertSame(0, $consumerCalls);
        self::assertSame(0, $this->httpCalls);
    }

    public function testUnavailablePreferredCredentialCannotFallBackToAnotherSecret(): void
    {
        $this->authorizeSyntheticConsumer();
        self::assertTrue((new Repository())->storeSecret($this->integrationId, 'access_token', 'synthetic-fallback-token'));$this->rebind();
        $reads = [];
        $filter = static function (string $sql) use (&$reads): string {
            if (str_contains($sql, 'SELECT ciphertext FROM '.Tables::integration_secrets())) {
                $reads[] = $sql;
                if (str_contains($sql, 'personal_access_token')) return 'SELECT * FROM digiforge_missing_consumer_secret';
            }
            return $sql;
        };
        $consumerCalls = 0;
        $results = $this->fault($filter, function () use (&$consumerCalls): array {
            return [
                (new ConnectionTester())->test($this->integrationId,$this->scopes['connection']),
                (new PrintifyCatalogSyncService())->syncBlueprint($this->integrationId, 101,$this->scopes['catalog']),
                (new PrintifyCatalogSyncService())->syncProviderVariants($this->integrationId, 101, 2,$this->scopes['catalog']),
                $this->consume(function () use (&$consumerCalls) { ++$consumerCalls; }),
            ];
        });
        foreach ($results as $index => $result) {
            self::assertWPError($result);
            self::assertSame($index===3?503:409, $result->get_error_data()['status']);
        }
        self::assertCount(1, $reads);
        foreach ($reads as $sql) self::assertStringNotContainsString("'access_token'", $sql);
        self::assertSame(0, $consumerCalls);
        self::assertSame(0, $this->httpCalls);
    }

    public function testCorruptPreferredCredentialCannotBeMaskedByValidLegacyCredential(): void
    {
        $this->authorizeSyntheticConsumer();
        $repository = new Repository();
        self::assertTrue($repository->storeSecret($this->integrationId, 'access_token', 'synthetic-fallback-token'));$this->rebind();
        global $wpdb;
        $wpdb->update(Tables::integration_secrets(), ['ciphertext'=>'corrupt:test:value'],
            ['integration_id'=>$this->integrationId,'secret_name'=>'personal_access_token']);
        $calls=0;$result=$this->consume(static function()use(&$calls){++$calls;return [];});
        self::assertWPError($result);
        self::assertSame('digiforge_printify_credential_decrypt', $result->get_error_code());
        self::assertSame(0,$calls);
        self::assertSame(0, $this->httpCalls);
    }

    private function governedResponse(array $response, ?callable $queryFault=null, string $expectedStatus='RESPONSE_REJECTED'):array
    {
        $fixture=new PrintifyApiApprovalFixture();$fixture->boot();$calls=0;$seenToken='';
        $workflow=new DigiForge\POD\PrintifyApiReadWorkflow();
        try {
            $plan=PrintifyApiApprovalFixture::checked($workflow->prepare('personalized_pod',$fixture->integration,'SHOPS_READ',[]));
            $auth=$fixture->approve($workflow,$plan,'printify-api-read');
            $fake=static function($pre,$args,$url)use($response,&$calls,&$seenToken){self::assertSame('https://api.printify.com/v1/shops.json',$url);self::assertSame('GET',$args['method']);++$calls;$seenToken=substr($args['headers']['Authorization'],7);$body=str_replace([self::TOKEN,'\\u0073ynthetic-consumer-safety-token'],[$seenToken,'\\u0073'.substr($seenToken,1)],$response['body']);return ['headers'=>[],'response'=>['code'=>$response['code']],'body'=>$body];};
            add_filter('pre_http_request',$fake,2,3);
            try {$result=$queryFault?$this->fault($queryFault,fn()=>(new ConnectionTester())->testPrintifyGoverned($fixture->integration,$plan['hash'],$auth['hash'])):(new ConnectionTester())->testPrintifyGoverned($fixture->integration,$plan['hash'],$auth['hash']);}
            finally {remove_filter('pre_http_request',$fake,2);}
            self::assertIsArray($result);self::assertFalse($result['ok']);self::assertSame([],$result['shops']);self::assertFalse($result['configuration_changed']);self::assertGreaterThan(0,$calls);self::assertLessThanOrEqual(3,$calls);if($response['code']===200)self::assertSame(1,$calls);
            $receipt=PrintifyApiApprovalFixture::checked($workflow->receipt($result['source_receipt_hash']));self::assertSame($response['code']===200?$expectedStatus:($response['code']===401?'FAILED':'UNKNOWN'),$receipt['status']);self::assertNull($receipt['data']);
            $stored=(new Repository())->find($fixture->integration);self::assertIsArray($stored);self::assertArrayNotHasKey('_connection_test',$stored['config']);self::assertStringNotContainsString(self::TOKEN,wp_json_encode([$stored['config'],$receipt]));self::assertNotSame('',$seenToken);self::assertStringNotContainsString($seenToken,wp_json_encode([$result,$stored['config'],$receipt]));
            return $result;
        } finally {$fixture->restore();}
    }

    public function testEscapedTokenEchoAndCredentialFieldsNeverBecomeConnectionEvidence(): void
    {
        $this->authorizeSyntheticConsumer();
        $responses = [
            ['code'=>200,'body'=>'[{"id":12,"title":"\\u0073ynthetic-consumer-safety-token","sales_channel":"etsy"}]'],
            ['code'=>401,'body'=>wp_json_encode(['message'=>self::TOKEN])],
            ['code'=>500,'body'=>wp_json_encode(['error_description'=>self::TOKEN])],
            ['code'=>200,'body'=>'[{"id":12,"title":"Synthetic shop","sales_channel":"etsy","access_token":"other-secret"}]'],
        ];
        foreach ($responses as $response) {
            $result=$this->governedResponse($response);
            self::assertStringNotContainsString(self::TOKEN,wp_json_encode($result));
        }
    }

    public function testMalformedShopsCannotBecomeVerifiedConnectionEvidence(): void
    {
        $this->authorizeSyntheticConsumer();
        foreach (['{"error":"unexpected"}', '[{"title":"no identity","sales_channel":"etsy"}]', '[{"id":-12,"title":"negative identity","sales_channel":"etsy"}]', '[{"id":[12],"title":"array identity","sales_channel":"etsy"}]'] as $body) {
            $this->governedResponse(['code'=>200,'body'=>$body],null,'UNKNOWN');
        }
    }

    public function testCredentialMetadataFailureAfterHttpCannotEscapeOrRecordReadiness(): void
    {
        $this->authorizeSyntheticConsumer();
        $metadataReads = 0;$httpReached=false;$faultReached=false;
        $filter = static function (string $sql) use (&$metadataReads,&$httpReached,&$faultReached): string {
            if (str_contains($sql, 'SELECT secret_name,fingerprint,updated_at FROM '.Tables::integration_secrets())) {
                ++$metadataReads;
                if ($httpReached){$faultReached=true;return 'SELECT * FROM digiforge_missing_post_http_metadata';}
            }
            return $sql;
        };
        $http=static function($pre)use(&$httpReached){$httpReached=true;return $pre;};
        add_filter('pre_http_request',$http,1);
        try {$this->governedResponse(['code'=>200,'body'=>'[{"id":12,"title":"Synthetic shop","sales_channel":"etsy"}]'],$filter,'UNKNOWN');}
        finally {remove_filter('pre_http_request',$http,1);}
        self::assertTrue($httpReached);self::assertTrue($faultReached);self::assertGreaterThanOrEqual(2,$metadataReads);
    }

    public function testConsumerExceptionsCannotEscapeWithCredentialMaterial(): void
    {
        $this->authorizeSyntheticConsumer();
        $throwing = static function (): never { throw new RuntimeException(self::TOKEN); };
        $sync = new PrintifyCatalogSyncService($throwing);
        foreach ([$sync->syncBlueprint($this->integrationId, 101,$this->scopes['catalog']),
            $sync->syncProviderVariants($this->integrationId, 101, 2,$this->scopes['catalog']),
            $this->consume($throwing)] as $result) {
            self::assertWPError($result);
            self::assertStringNotContainsString(self::TOKEN, wp_json_encode($result));
        }
        self::assertSame(0, $this->httpCalls);
    }

    public function testLegacyDirectBlueprintSyncCannotBypassGovernedAdmission(): void
    {
        $this->authorizeSyntheticConsumer();
        $calls=0;
        $client=new PrintifyCatalogClient(static function() use (&$calls): array { ++$calls; return []; });
        $result=(new PrintifyCatalogSyncService(static fn()=>$client))->syncBlueprint($this->integrationId,101,$this->scopes['catalog']);
        self::assertWPError($result);
        self::assertSame('digiforge_printify_governed_catalog_required',$result->get_error_code());
        self::assertSame(0,$calls);
        self::assertStringNotContainsString(self::TOKEN,wp_json_encode($result));
        self::assertSame(0,$this->httpCalls);
    }
}
