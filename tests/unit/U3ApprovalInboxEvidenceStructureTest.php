<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3ApprovalInboxEvidenceStructureTest extends TestCase
{
    public function testGate2PanelShowsSemanticChecksAndLatestRevisionEvidence(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Portal/U3ApprovalInbox.php');
        self::assertIsString($source);
        self::assertStringContainsString('Semantic / policy QA evidence', $source);
        self::assertStringContainsString("target_type='plan'", $source);
        self::assertStringContainsString("check_type LIKE 'semantic_%%'", $source);
        self::assertStringContainsString('ORDER BY ar2.id DESC LIMIT 1', $source);
        self::assertStringContainsString('Gate 2 remains human-controlled', $source);
    }

    public function testPanelDoesNotEnableExternalActions(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Portal/U3ApprovalInbox.php');
        self::assertIsString($source);
        self::assertStringNotContainsString("setting_key = 'etsy_publish'", $source);
        self::assertStringNotContainsString("setting_key = 'printify'", $source);
        self::assertStringNotContainsString("setting_key = 'gelato'", $source);
    }
    public function testStageFInboxAggregatesPendingOperationalDecisionsWithoutExecutingThem(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Portal/U3ApprovalInbox.php');
        self::assertIsString($source);
        foreach (['listing_readiness_reviews', 'personalization_submissions', 'pod_readiness_reviews', 'fulfillment_readiness_reviews', 'Operational approval & exception inbox', 'read-only and cannot activate or execute an external action'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
        self::assertStringNotContainsString('wp_remote_request', $source);
        self::assertStringNotContainsString('Settings::set', $source);
    }

}
