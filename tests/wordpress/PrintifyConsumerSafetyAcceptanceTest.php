<?php
declare(strict_types=1);

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
    private int $integrationId;
    private int $httpCalls = 0;
    private const TOKEN = 'synthetic-consumer-safety-token';
    private mixed $httpFilter;

    public function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        Settings::protectProduction();
        $repository = new Repository();
        $created = $repository->create(['provider'=>'printify', 'environment'=>'test',
            'connection_key'=>'consumer-safety-'.wp_rand(), 'status'=>'CONFIGURED']);
        self::assertIsArray($created);
        $this->integrationId = (int) $created['id'];
        self::assertTrue($repository->storeSecret($this->integrationId, 'personal_access_token', self::TOKEN));
        self::assertIsArray($repository->setEnabled($this->integrationId, true));
        $this->httpFilter = function () {
            ++$this->httpCalls;
            return new WP_Error('blocked_mock_network', 'Synthetic HTTP boundary');
        };
        add_filter('pre_http_request', $this->httpFilter, 1, 3);
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', $this->httpFilter, 1);
        Settings::protectProduction();
        parent::tearDown();
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
                (new ConnectionTester())->test($this->integrationId),
                (new PrintifyCatalogSyncService())->syncBlueprint($this->integrationId, 101),
                (new PrintifyCatalogSyncService())->syncProviderVariants($this->integrationId, 101, 2),
                (new PrintifyScopedCredentialRetriever())->consume($this->integrationId, function () use (&$consumerCalls) { ++$consumerCalls; }),
            ];
        });
        foreach ($results as $result) {
            self::assertWPError($result);
            self::assertSame('integration_read_unavailable', $result->get_error_code());
        }
        self::assertSame(0, $consumerCalls);
        self::assertSame(0, $this->httpCalls);
    }

    public function testUnavailablePreferredCredentialCannotFallBackToAnotherSecret(): void
    {
        $this->authorizeSyntheticConsumer();
        self::assertTrue((new Repository())->storeSecret($this->integrationId, 'access_token', 'synthetic-fallback-token'));
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
                (new ConnectionTester())->test($this->integrationId),
                (new PrintifyCatalogSyncService())->syncBlueprint($this->integrationId, 101),
                (new PrintifyCatalogSyncService())->syncProviderVariants($this->integrationId, 101, 2),
                (new PrintifyScopedCredentialRetriever())->consume($this->integrationId, function () use (&$consumerCalls) { ++$consumerCalls; }),
            ];
        });
        foreach ($results as $result) {
            self::assertWPError($result);
            self::assertSame(503, $result->get_error_data()['status']);
        }
        self::assertCount(4, $reads);
        foreach ($reads as $sql) self::assertStringNotContainsString("'access_token'", $sql);
        self::assertSame(0, $consumerCalls);
        self::assertSame(0, $this->httpCalls);
    }

    public function testCorruptPreferredCredentialCannotBeMaskedByValidLegacyCredential(): void
    {
        $this->authorizeSyntheticConsumer();
        $repository = new Repository();
        self::assertTrue($repository->storeSecret($this->integrationId, 'access_token', 'synthetic-fallback-token'));
        global $wpdb;
        $wpdb->update(Tables::integration_secrets(), ['ciphertext'=>'corrupt:test:value'],
            ['integration_id'=>$this->integrationId,'secret_name'=>'personal_access_token']);
        $result = (new ConnectionTester())->test($this->integrationId);
        self::assertWPError($result);
        self::assertSame('credential_decryption_failed', $result->get_error_code());
        self::assertSame(0, $this->httpCalls);
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
            $filter = static fn() => ['headers'=>[],'response'=>['code'=>$response['code']], 'body'=>$response['body']];
            add_filter('pre_http_request', $filter, 2);
            try { $result = (new ConnectionTester())->test($this->integrationId); }
            finally { remove_filter('pre_http_request', $filter, 2); }
            self::assertWPError($result);
            self::assertSame('printify_protected_response', $result->get_error_code());
            self::assertStringNotContainsString(self::TOKEN, wp_json_encode($result));
            $stored = (new Repository())->find($this->integrationId);
            self::assertIsArray($stored);
            self::assertFalse($stored['config']['_connection_test']['ok']);
            self::assertStringNotContainsString(self::TOKEN, wp_json_encode($stored['config']));
        }
    }

    public function testMalformedShopsCannotBecomeVerifiedConnectionEvidence(): void
    {
        $this->authorizeSyntheticConsumer();
        foreach (['{"error":"unexpected"}', '[{"title":"no identity","sales_channel":"etsy"}]', '[{"id":-12,"title":"negative identity","sales_channel":"etsy"}]', '[{"id":[12],"title":"array identity","sales_channel":"etsy"}]'] as $body) {
            $filter = static fn() => ['headers'=>[],'response'=>['code'=>200], 'body'=>$body];
            add_filter('pre_http_request', $filter, 2);
            try { $result = (new ConnectionTester())->test($this->integrationId); }
            finally { remove_filter('pre_http_request', $filter, 2); }
            self::assertWPError($result);
            self::assertSame('printify_invalid_response', $result->get_error_code());
            self::assertFalse((new Repository())->find($this->integrationId)['config']['_connection_test']['ok']);
        }
    }

    public function testCredentialMetadataFailureAfterHttpCannotEscapeOrRecordReadiness(): void
    {
        $this->authorizeSyntheticConsumer();
        $metadataReads = 0;
        $filter = static function (string $sql) use (&$metadataReads): string {
            if (str_contains($sql, 'SELECT secret_name,fingerprint,updated_at FROM '.Tables::integration_secrets())) {
                ++$metadataReads;
                if ($metadataReads > 1) return 'SELECT * FROM digiforge_missing_post_http_metadata';
            }
            return $sql;
        };
        $http = static fn() => ['headers'=>[], 'response'=>['code'=>200],
            'body'=>'[{"id":12,"title":"Synthetic shop","sales_channel":"etsy"}]'];
        add_filter('pre_http_request', $http, 2);
        try { $result = $this->fault($filter, fn() => (new ConnectionTester())->test($this->integrationId)); }
        finally { remove_filter('pre_http_request', $http, 2); }
        self::assertWPError($result);
        self::assertSame('integration_connection_test_evidence_not_recorded', $result->get_error_code());
        self::assertSame(2, $metadataReads);
        $stored = (new Repository())->find($this->integrationId);
        self::assertIsArray($stored);
        self::assertArrayNotHasKey('_connection_test', $stored['config']);
    }

    public function testConsumerExceptionsCannotEscapeWithCredentialMaterial(): void
    {
        $this->authorizeSyntheticConsumer();
        $throwing = static function (): never { throw new RuntimeException(self::TOKEN); };
        $sync = new PrintifyCatalogSyncService($throwing);
        foreach ([$sync->syncBlueprint($this->integrationId, 101),
            $sync->syncProviderVariants($this->integrationId, 101, 2),
            (new PrintifyScopedCredentialRetriever())->consume($this->integrationId, $throwing)] as $result) {
            self::assertWPError($result);
            self::assertStringNotContainsString(self::TOKEN, wp_json_encode($result));
        }
        self::assertSame(0, $this->httpCalls);
    }

    public function testLegacySecretAndReadOnlyBlueprintSuccessRemainSupported(): void
    {
        $this->authorizeSyntheticConsumer();
        global $wpdb;
        $wpdb->delete(Tables::integration_secrets(), ['integration_id'=>$this->integrationId,'secret_name'=>'personal_access_token']);
        self::assertTrue((new Repository())->storeSecret($this->integrationId, 'access_token', self::TOKEN));
        $calls = [];
        $client = new PrintifyCatalogClient(static function (string $url, array $args) use (&$calls): array {
            $calls[] = $url;
            self::assertSame('Bearer '.self::TOKEN, $args['headers']['Authorization']);
            return ['response'=>['code'=>200], 'body'=>str_contains($url, 'print_providers') ? '[{"id":2}]' : '{"id":101,"title":"Synthetic blueprint"}'];
        });
        $result = (new PrintifyCatalogSyncService(static fn() => $client))->syncBlueprint($this->integrationId, 101);
        self::assertIsArray($result);
        self::assertCount(2, $calls);
        self::assertSame(101, $result['blueprint']['id']);
        self::assertStringNotContainsString(self::TOKEN, wp_json_encode($result));
        self::assertSame(0, $this->httpCalls);
    }
}
