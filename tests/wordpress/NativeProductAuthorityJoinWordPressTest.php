<?php
declare(strict_types=1);

use DigiForge\Connector\Printify\{NativeBridge, NativeBusinessMutationAssociation};
use DigiForge\Database\Tables;

require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/NativeBusinessMutationFixture.php';
require_once __DIR__.'/../support/NativePrintifyContractFixture.php';

final class NativeProductAuthorityJoinWordPressTest extends WP_UnitTestCase
{
    use NativeBusinessMutationFixture;

    public function testReviewedProductAssociationIsRevalidatedWithoutIssuingNativeAuthority(): void
    {
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
        DigiForge\Core\Activator::activate();
        $fixture = new PrintifyApiApprovalFixture();
        $fixture->boot();
        $http = 0;
        $ciphertext = 0;
        $denyHttp = static function () use (&$http) { ++$http; throw new RuntimeException('Fixture HTTP prohibited'); };
        $denyCiphertext = static function (string $sql) use (&$ciphertext): string {
            if (preg_match('/^\s*SELECT\s+[^;]*\bciphertext\b/i', $sql)) {
                ++$ciphertext;
                throw new RuntimeException('Fixture credential read prohibited');
            }
            return $sql;
        };
        global $wpdb;
        $snapshots = [];
        foreach ([Tables::opportunities(), Tables::product_families(), Tables::products(), Tables::product_versions(),
            Tables::production_plans(), Tables::pod_mappings(), Tables::pod_business_mappings(), Tables::asset_specs(),
            Tables::production_plan_assets()] as $table) {
            $snapshots[$table] = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);
        }
        try {
            $f = $this->businessMutationFixture($fixture);
            add_filter('pre_http_request', $denyHttp, PHP_INT_MAX);
            add_filter('query', $denyCiphertext, PHP_INT_MAX);
            $scope = $f['scope'];
            $m = $f['mapping_id'];
            $s = $f['spec_id'];
            $p = $f['plan_hash'];
            $source = PrintifyApiApprovalFixture::checked(NativeBusinessMutationAssociation::source($scope, 1, $m, $s, $p));
            $proposal = PrintifyApiApprovalFixture::checked(NativeBusinessMutationAssociation::propose(
                $scope, 1, $m, $s, $p, $source['source_hash'], 0, time(), time()+600, wp_generate_uuid4()
            ));
            PortalAccessFixture::login($fixture->reviewer);
            $review = PrintifyApiApprovalFixture::checked(NativeBusinessMutationAssociation::decide(
                $scope, $fixture->reviewer, $m, $s, $p, $proposal['revision'], $proposal['proposal_hash'], 'APPROVED', wp_generate_uuid4()
            ));
            $fixture->owner();
            $q = NativePrintifyContractFixture::mutation('AUTHORIZE');
            $q['binding'] = array_replace($q['binding'], [
                'scope_fingerprint'=>$scope->fingerprint(), 'integration_id'=>$scope->integrationId,
                'credential_reference'=>$scope->credential->integrationId, 'provider_shop_id'=>$scope->providerShopId,
                'environment'=>$scope->environment, 'authorization_revision'=>$scope->revision,
                'approval_reference'=>$source['plan_policy_hash'], 'payload_fingerprint'=>$source['payload_fingerprint'],
            ]);
            $q['context'] = array_replace($q['context'], [
                'logical_reference'=>'product-source:'.$m.':'.$s, 'authorization_revision'=>$scope->revision,
                'approval_reference'=>$source['plan_policy_hash'],
            ]);
            $q['evidence_references'] = [$p, $review['proposal_hash']];
            $port = NativeBridge::connect('1.0.0');
            self::assertNotWPError($port);
            $result = $port->exchange($q);
            self::assertWPError($result);
            self::assertSame('printify_native_product_revision_authority_required', $result->get_error_code());
            self::assertFalse($result->get_error_data()['external_execution_authorized']);
            $wrong = $q;
            $wrong['context']['logical_reference'] = 'product-source:'.($m + 100000).':'.$s;
            self::assertSame('printify_native_current_product_association_required', $port->exchange($wrong)->get_error_code());
            $wrong = $q;
            $wrong['evidence_references'][1] = str_repeat('0',64);
            self::assertSame('printify_native_mutation_product_association_mismatch', $port->exchange($wrong)->get_error_code());
            self::assertSame(1, $wpdb->update(Tables::product_versions(), ['notes'=>'changed product version source'], ['id'=>$f['business_version_id']]));
            self::assertSame('printify_native_current_product_association_required', $port->exchange($q)->get_error_code());
            self::assertSame(0, $http);
            self::assertSame(0, $ciphertext);
        } finally {
            remove_filter('pre_http_request', $denyHttp, PHP_INT_MAX);
            remove_filter('query', $denyCiphertext, PHP_INT_MAX);
            foreach ($snapshots as $table=>$rows) {
                $wpdb->query("DELETE FROM $table");
                foreach ($rows as $row) {
                    $wpdb->insert($table, $row, array_fill(0, count($row), '%s'));
                }
            }
            $fixture->restore();
        }
    }
}
