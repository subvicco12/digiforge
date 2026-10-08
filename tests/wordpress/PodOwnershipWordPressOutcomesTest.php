<?php
declare(strict_types=1);

final class PodOwnershipWordPressOutcomesTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testMissingApprovedOwnershipFailsClosed(): void
    {
        $result = (new \DigiForge\POD\BusinessScopeRepository())->assertActiveOwnershipForMapping(2147483647);
        self::assertWPError($result);
        self::assertSame('digiforge_scope_inactive', $result->get_error_code());
    }

    public function testInvalidMappingIdIsRejectedBeforeQuery(): void
    {
        $result = (new \DigiForge\POD\BusinessScopeRepository())->assertActiveOwnershipForMapping(0);
        self::assertWPError($result);
        self::assertSame('digiforge_scope_validation', $result->get_error_code());
    }

    public function testQueryFailureIsNotMisclassifiedAsMissingOwnership(): void
    {
        global $wpdb;
        $table = \DigiForge\Database\Tables::pod_business_mappings();
        $renamed = $table . '_unavailable_test';
        self::assertSame(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=%s', $renamed)));
        $wpdb->query('RENAME TABLE ' . $table . ' TO ' . $renamed);
        try {
            $result = (new \DigiForge\POD\BusinessScopeRepository())->assertActiveOwnershipForMapping(2147483647);
            self::assertWPError($result);
            self::assertSame('digiforge_scope_evidence_unavailable', $result->get_error_code());
        } finally {
            $wpdb->query('RENAME TABLE ' . $renamed . ' TO ' . $table);
        }
    }
}
