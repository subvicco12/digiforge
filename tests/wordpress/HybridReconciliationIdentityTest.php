<?php

declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\Orders\ApprovedPodMappingResolver;
use DigiForge\Orders\ReconciliationReadModel;
use DigiForge\Orders\Repository;

/** Real stored identity joins: a POD listing binding overrides version-level digital classification. */
final class HybridReconciliationIdentityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
    }

    public function testHybridLineWithoutProviderMappingCannotReconcile(): void
    {
        $fixture = $this->fixture(true, true);
        self::assertFalse(ApprovedPodMappingResolver::isDigital($fixture['version'], $fixture['listing']));
        $result = (new ReconciliationReadModel())->forOrder($fixture['order']);
        self::assertSame('AVAILABLE', $result['query_state']);
        self::assertSame(1, $result['line_item_count']);
        self::assertSame(0, $result['invalid_line_items']);
        self::assertSame(1, $result['unmapped_pod_line_items']);
        self::assertFalse($result['reconciled']);
        self::assertFalse($result['fulfillment_authorized']);
        self::assertFalse($result['external_execution_performed']);
    }

    public function testHybridMissingMappingIsReportedAsReadOnlyDiscrepancy(): void
    {
        $fixture = $this->fixture(true, true);
        $result = (new ReconciliationReadModel())->discrepancyProjection($fixture['order']);
        self::assertSame('AVAILABLE', $result['query_state']);
        self::assertCount(1, $result['items']);
        $line = $result['items'][0];
        self::assertSame($fixture['line'], (int)$line['id']);
        self::assertSame($fixture['version'], (int)$line['product_version_id']);
        self::assertSame(0, (int)$line['provider_mapping_id']);
        self::assertTrue($line['read_only']);
        self::assertFalse($line['fulfillment_authorized']);
        self::assertFalse($line['retry_permitted']);
        self::assertFalse($line['external_execution_authorized']);
    }

    public function testDigitalLineRemainsReconciledWhenOnlyAnotherListingHasPodBinding(): void
    {
        $fixture = $this->fixture(true, false);
        // A second listing for the same product version is physical; this line stays digital.
        $otherListing = $this->listing($fixture['version']);
        $this->binding($otherListing, $fixture['version']);
        self::assertTrue(ApprovedPodMappingResolver::isDigital($fixture['version'], $fixture['listing']));
        $model = new ReconciliationReadModel();
        $result = $model->forOrder($fixture['order']);
        self::assertSame('AVAILABLE', $result['query_state']);
        self::assertSame(0, $result['unmapped_pod_line_items']);
        self::assertTrue($result['reconciled']);
        self::assertFalse($result['fulfillment_authorized']);
        self::assertSame(['query_state' => 'AVAILABLE', 'items' => []], $model->discrepancyProjection($fixture['order']));
    }

    public function testPhysicalLineWithoutDigitalRegistryStillRequiresProviderMapping(): void
    {
        $fixture = $this->fixture(false, false);
        $model = new ReconciliationReadModel();
        $result = $model->forOrder($fixture['order']);
        self::assertSame(1, $result['unmapped_pod_line_items']);
        self::assertFalse($result['reconciled']);
        self::assertCount(1, $model->discrepancyProjection($fixture['order'])['items']);
    }

    public function testUnavailableListingBindingEvidenceFailsClosed(): void
    {
        $fixture = $this->fixture(true, true);
        global $wpdb;
        $deny = static function (string $sql): string {
            return str_starts_with($sql, 'SELECT ') && str_contains($sql, Tables::listing_pod_bindings())
                ? 'SELECT FROM /* synthetic read failure; no mutation */' : $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $deny);
        try {
            $model = new ReconciliationReadModel();
            $result = $model->forOrder($fixture['order']);
            self::assertSame('UNAVAILABLE', $result['query_state']);
            self::assertFalse($result['reconciled']);
            self::assertNull($result['unmapped_pod_line_items']);
            self::assertFalse($result['fulfillment_authorized']);
            self::assertSame(['query_state' => 'UNAVAILABLE', 'items' => []], $model->discrepancyProjection($fixture['order']));
        } finally {
            remove_filter('query', $deny);
            $wpdb->suppress_errors($previous);
        }
    }

    /** Rows are local fixtures; all classification and reconciliation use production SQL. */
    private function fixture(bool $digital, bool $bound): array
    {
        global $wpdb;
        $times = $this->times();
        self::assertSame(1, $wpdb->insert(Tables::products(), $times + [
            'product_family_id' => 0, 'name' => 'Synthetic reconciliation product',
        ]));
        $product = (int)$wpdb->insert_id;
        self::assertSame(1, $wpdb->insert(Tables::product_versions(), $times + [
            'product_id' => $product, 'version_label' => 'synthetic-v1',
        ]));
        $version = (int)$wpdb->insert_id;
        if ($digital) {
            self::assertSame(1, $wpdb->insert(Tables::digital_products(), $times + [
                'product_id' => $product, 'product_version_id' => $version,
                'name' => 'Synthetic digital registry', 'category' => 'fixture',
            ]));
        }
        $listing = $this->listing($version);
        if ($bound) $this->binding($listing, $version);
        $repository = new Repository();
        $order = $repository->createOrder([
            'channel' => 'etsy', 'environment' => 'test', 'shop_reference' => 'synthetic-shop',
        ]);
        self::assertNotWPError($order);
        $line = $repository->addLineItem([
            'order_id' => $order['id'], 'listing_id' => $listing,
            'product_version_id' => $version, 'provider_mapping_id' => 0,
        ]);
        self::assertNotWPError($line);
        return ['order' => (int)$order['id'], 'line' => (int)$line['id'], 'version' => $version, 'listing' => $listing];
    }

    private function listing(int $version): int
    {
        global $wpdb;
        self::assertSame(1, $wpdb->insert(Tables::listings(), $this->times() + [
            'product_version_id' => $version, 'channel' => 'etsy', 'environment' => 'test',
            'shop_reference' => 'synthetic-shop', 'title' => 'Synthetic local listing',
        ]));
        return (int)$wpdb->insert_id;
    }

    private function binding(int $listing, int $version): void
    {
        global $wpdb;
        $times = $this->times();
        self::assertSame(1, $wpdb->insert(Tables::pod_mappings(), $times + [
            'product_version_id' => $version, 'production_plan_id' => 1, 'provider' => 'printify',
            'environment' => 'test', 'provider_product_key' => 'synthetic-product',
            'mapping_version' => wp_generate_uuid4(), 'state' => 'APPROVED',
            'approved_by' => 1, 'approved_at' => $times['created_at'],
        ]));
        $mapping = (int)$wpdb->insert_id;
        self::assertSame(1, $wpdb->insert(Tables::listing_pod_bindings(), $times + [
            'listing_id' => $listing, 'provider_mapping_id' => $mapping,
            'readiness_hash' => hash('sha256', 'synthetic reconciliation identity'),
        ]));
    }

    private function times(): array
    {
        $now = current_time('mysql', true);
        return ['created_at' => $now, 'updated_at' => $now];
    }
}
