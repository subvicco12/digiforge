<?php
declare(strict_types=1);

use DigiForge\Connector\Printify\{
    CredentialReference, NativeAuthorityAdapter, NativeBuildIdentity, NativeHeldJournal, NativeHeldJournalSchema,
    NativePanelAccessAdapter, ShopBinding
};
use DigiForgePrintifyApi\Runtime;

final class NativeHeldInspectionWordPressTest extends WP_UnitTestCase
{
    public function testExactHeldReadbackPreservesUnknownAndRequiresCurrentScope(): void
    {
        $root = dirname((new ReflectionClass(NativeBuildIdentity::class))->getFileName(), 4);
        require_once $root . '/tests/wordpress/fixtures/PrintifyApiApprovalFixture.php';
        require_once $root . '/tests/support/NativePrintifyContractFixture.php';
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
        $fixture = new PrintifyApiApprovalFixture();
        $fixture->boot();
        try {
            $fixture->setting('stop_all', true);
            $fixture->setting('automation_armed', false);
            $fixture->setting('activation_authorized', false);
            wp_get_current_user()->add_cap('digiforge_printify_review_shop_12');
            $v = PrintifyApiApprovalFixture::checked((new DigiForge\POD\ScopedProviderConnection())
                ->verified('personalized_pod', $fixture->integration, 'printify.connection.read'));
            $scope = new ShopBinding(2, 'personalized_pod', $fixture->integration, '12', $v['environment'],
                $v['review_revision'], new CredentialReference($fixture->integration), $v['binding_hash'], $v['expires_at']);
            $nativeScope = PrintifyApiApprovalFixture::checked(NativeAuthorityAdapter::scope($scope, 1));
            $account = $nativeScope['scope']['external_account_reference'];
            define('DIGIFORGE_PRINTIFY_OFFLINE_ACCOUNT', $account);
            $session = PrintifyApiApprovalFixture::checked(NativeAuthorityAdapter::session(1));
            $context = ['version'=>'1.0.0', 'view'=>'unknown', 'session_fingerprint'=>$session['fingerprint'],
                'csrf_token'=>wp_create_nonce(NativePanelAccessAdapter::nonceAction($scope, 'unknown'))];
            $binding = array_replace(NativePrintifyContractFixture::binding(), [
                'operation_id'=>hash('sha256', wp_generate_uuid4()), 'scope_fingerprint'=>$scope->fingerprint(),
                'integration_id'=>$scope->integrationId, 'credential_reference'=>$scope->integrationId,
                'provider_shop_id'=>'12', 'environment'=>'test', 'authorization_revision'=>$scope->revision,
            ]);
            global $wpdb;
            foreach (NativeHeldJournalSchema::statements() as $sql) {
                self::assertNotFalse($wpdb->query(preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $sql)));
            }
            $journal = new NativeHeldJournal();
            $key = hash('sha256', 'fixture:'.$binding['operation_id']);
            $record = PrintifyApiApprovalFixture::checked($journal->register($binding, $key));
            self::assertSame($record, $journal->register($binding, $key));
            $read = static fn(array $b): array => Runtime::inspectNativeHeldOperation('12', $account, $scope, $b, $context);
            $held = $read($binding);
            self::assertSame('LOCAL_HELD_OBSERVED', $held['state'], json_encode($held));
            foreach (['payload_fingerprint'=>str_repeat('0',64), 'expected_product_version'=>2, 'provider_shop_id'=>'13',
                'approval_reference'=>str_repeat('1',64)] as $field=>$value) {
                self::assertSame('BLOCKED', $read(array_replace($binding, [$field=>$value]))['state']);
            }
            PrintifyApiApprovalFixture::checked($journal->transition($binding['operation_id'], 'personalized_pod', 1, 1, 'FENCE'));
            PrintifyApiApprovalFixture::checked($journal->transition($binding['operation_id'], 'personalized_pod', 1, 2, 'UNKNOWN'));
            $unknown = $read($binding);
            self::assertSame('UNKNOWN', $unknown['state']);
            self::assertFalse($unknown['retry_permitted']);
            self::assertFalse($unknown['provider_outcome_confirmed']);
            self::assertSame($unknown, $read($binding));
            wp_set_current_user(0);
            self::assertSame('DENIED', $read($binding)['state']);
        } finally {
            $fixture->restore();
        }
    }
}
