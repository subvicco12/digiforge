<?php
declare(strict_types=1);

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Integrations\Repository as IntegrationRepository;
use DigiForge\POD\PrintifyCatalogReadRepository;
use DigiForge\POD\PrintifyLiveTransportInterlock;
use DigiForge\POD\PrintifyScopedCredentialRetriever;
use DigiForge\POD\PrintifyCatalogSyncService;

/**
 * Issue #1173: an enabled connector is metadata, never execution authority.
 * This extends the catalog-read integration fixture from the audited #1172 head.
 */
final class PrintifyEnabledStopAllIsolationTest extends WP_UnitTestCase {
    public function testEnabledConfiguredConnectorDoesNotUnlockOrdinaryPrintifyPaths(): void {
        DigiForge\Core\Activator::activate();
        Settings::protectProduction();
        $repository = new IntegrationRepository();
        $suffix = wp_rand(100000, 999999);
        $integration = $repository->create([
            'provider' => 'printify',
            'environment' => 'test',
            'connection_key' => 'stop-all-isolation-' . $suffix,
            'status' => 'CONFIGURED',
        ]);
        self::assertIsArray($integration);
        $id = (int) $integration['id'];
        self::assertTrue($repository->storeSecret($id, 'personal_access_token', 'synthetic-test-token'));
        $enabled = $repository->setEnabled($id, true);
        self::assertIsArray($enabled);
        self::assertTrue($enabled['enabled']);
        self::assertTrue(Settings::safety_locked());
        self::assertFalse(Settings::is_enabled('printify'));

        $networkCalls = 0;
        $blockNetwork = static function ($preempt, $args, $url) use (&$networkCalls) {
            if (str_contains((string) $url, 'printify.com')) {
                ++$networkCalls;
                return new WP_Error('unexpected_printify_network', 'Unexpected Printify HTTP request');
            }
            return $preempt;
        };
        add_filter('pre_http_request', $blockNetwork, 10, 3);
        try {
            $credentialCalls = 0;
            $credential = (new PrintifyScopedCredentialRetriever())->consume(
                $id,
                static function (string $token) use (&$credentialCalls): void { ++$credentialCalls; }
            );
            self::assertWPError($credential);
            self::assertSame(0, $credentialCalls);

            $sync = (new PrintifyCatalogSyncService())->syncProviderVariants($id, 101, 2);
            self::assertWPError($sync);
            self::assertSame('digiforge_printify_locked', $sync->get_error_code());

            $tester = new DigiForge\Integrations\ConnectionTester();
            $test = $tester->test($id);
            self::assertWPError($test);
            self::assertSame('printify_scoped_authorization_required', $test->get_error_code());

            foreach (['PROVIDER_ORDER_SUBMIT', 'PROVIDER_PRODUCTION_AUTHORIZE'] as $action) {
                $permit = [
                    'state' => 'ADAPTER_CALL_PERMITTED',
                    'nonce_consumed' => true,
                    'external_execution_performed' => false,
                    'action' => $action,
                ];
                $denied = PrintifyLiveTransportInterlock::authorize($permit, ['action' => $action]);
                self::assertWPError($denied);
                self::assertSame('digiforge_printify_interlock_locked', $denied->get_error_code());
            }
            $preflight = (new DigiForge\Launch\PrintifyActivationPreflight())->report();
            self::assertSame('BLOCKED', $preflight['status']);
            self::assertFalse($preflight['checks']['stop_all_released']);
            self::assertFalse($preflight['checks']['activation_authorized']);
            self::assertFalse($preflight['checks']['automation_armed']);
            self::assertFalse($preflight['network_requests_performed']);
            self::assertFalse($preflight['external_actions_performed']);
            self::assertSame(0, $networkCalls);
        } finally {
            remove_filter('pre_http_request', $blockNetwork, 10);
        }
    }
}
