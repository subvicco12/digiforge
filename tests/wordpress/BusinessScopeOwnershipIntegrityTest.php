<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\POD\BusinessScopeRepository;

final class BusinessScopeOwnershipIntegrityTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testUniqueMatchingOwnershipIsAccepted(): void
    {
        [$mapping, $owner] = $this->fixture();
        $result = (new BusinessScopeRepository())->assertActiveOwnershipForMapping($mapping);
        self::assertIsArray($result);
        self::assertSame($owner, (int) $result['id']);
    }

    public function testMissingProviderMappingIsRejected(): void
    {
        global $wpdb;
        [$mapping] = $this->fixture();
        self::assertSame(1, $wpdb->delete(Tables::pod_mappings(), ['id' => $mapping]));
        $this->assertBlocked($mapping);
    }

    public function testProductVersionDriftIsRejected(): void
    {
        global $wpdb;
        [$mapping, $owner] = $this->fixture();
        self::assertSame(1, $wpdb->update(Tables::pod_business_mappings(), ['product_version_id' => 902], ['id' => $owner]));
        $this->assertBlocked($mapping);
    }

    public function testConflictingSecondOwnerIsRejected(): void
    {
        global $wpdb;
        [$mapping, $owner] = $this->fixture();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE id=%d', $owner), ARRAY_A);
        unset($row['id']);
        $row['product_version_id'] = 902;
        self::assertSame(1, $wpdb->insert(Tables::pod_business_mappings(), $row));
        $this->assertBlocked($mapping);
    }

    private function assertBlocked(int $mapping): void
    {
        $result = (new BusinessScopeRepository())->assertActiveOwnershipForMapping($mapping);
        self::assertWPError($result);
        self::assertSame('digiforge_scope_inactive', $result->get_error_code());
    }

    /** Direct stored rows deliberately exercise corruption that normal creation rejects. */
    private function fixture(): array
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $times = ['created_at' => $now, 'updated_at' => $now];
        self::assertSame(1, $wpdb->insert(Tables::businesses(), $times + ['business_key' => 'integrity-'.wp_generate_uuid4(), 'status' => 'ACTIVE']));
        $business = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert(Tables::stores(), $times + ['business_id' => $business, 'store_key' => 'integrity-store', 'status' => 'ACTIVE']));
        $store = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert(Tables::product_programs(), $times + ['business_id' => $business, 'store_id' => $store, 'program_key' => 'PERSONALIZED_POD', 'status' => 'ACTIVE']));
        $program = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert(Tables::pod_mappings(), $times + ['product_version_id' => 901, 'production_plan_id' => 901, 'provider' => 'printify', 'environment' => 'test', 'provider_product_key' => 'integrity', 'mapping_version' => wp_generate_uuid4()]));
        $mapping = (int) $wpdb->insert_id;
        self::assertSame(1, $wpdb->insert(Tables::pod_business_mappings(), $times + ['business_id' => $business, 'store_id' => $store, 'product_program_id' => $program, 'product_version_id' => 901, 'provider_mapping_id' => $mapping, 'state' => 'APPROVED', 'approved_by' => 1, 'approved_at' => $now]));
        return [$mapping, (int) $wpdb->insert_id];
    }
}
