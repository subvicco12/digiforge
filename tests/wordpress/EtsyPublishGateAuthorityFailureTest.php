<?php
declare(strict_types=1);

final class EtsyPublishGateAuthorityFailureTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        \DigiForge\Core\Activator::activate();
    }

    public function testFailedIntentAuthorityReadBlocksPublishing(): void
    {
        $table = \DigiForge\Database\Tables::etsy_intents();
        $filter = static function (string $sql) use ($table): string {
            if (str_contains($sql, 'SELECT * FROM ' . $table . ' WHERE id=')) {
                return 'SELECT * FROM digiforge_test_nonexistent_etsy_intent_authority';
            }
            return $sql;
        };
        add_filter('query', $filter);
        try {
            $result = (new \DigiForge\Listings\EtsyPublishAuthorizationGate())->authorize([
                'state' => 'ETSY_PREPUBLISH_EVIDENCE_READY',
                'publish_authorized' => false,
                'etsy_api_invoked' => false,
                'external_execution_performed' => false,
                'listing_id' => 2147483647,
                'etsy_intent_id' => 2147483647,
                'draft_package_id' => 2147483647,
                'readiness_hash' => str_repeat('a', 64),
            ]);
            self::assertWPError($result);
            self::assertSame('digiforge_etsy_publish_gate_evidence_unavailable', $result->get_error_code());
        } finally {
            remove_filter('query', $filter);
        }
    }

    public function testIncompletePrepublishEvidenceIsRejected(): void
    {
        $result = (new \DigiForge\Listings\EtsyPublishAuthorizationGate())->authorize([]);
        self::assertWPError($result);
        self::assertSame('digiforge_etsy_publish_gate_evidence', $result->get_error_code());
    }
}
