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

    public function testPendingHeaderCountsAllRecordsBeyondTheEvidenceWindow(): void
    {
        require_once __DIR__ . '/../../includes/Portal/U3ApprovalInbox.php';
        require_once __DIR__ . '/../../includes/Database/Tables.php';
        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $query = '';
            public function get_var(string $query): int { $this->query = $query; return 217; }
        };
        try {
            $method = new ReflectionMethod(\DigiForge\Portal\U3ApprovalInbox::class, 'pendingOperationalCount');
            $inbox = (new ReflectionClass(\DigiForge\Portal\U3ApprovalInbox::class))->newInstanceWithoutConstructor();
            self::assertSame(217, $method->invoke($inbox));
            $query = $GLOBALS['wpdb']->query;
            foreach (['listing_readiness_reviews', 'personalization_submissions', 'pod_readiness_reviews', 'fulfillment_readiness_reviews'] as $table) {
                self::assertStringContainsString($table, $query);
            }
            self::assertStringNotContainsString('LIMIT', $query);
            self::assertSame(3, substr_count($query, "decision='PENDING'"));
            self::assertStringContainsString("review_status NOT IN ('APPROVED','REJECTED')", $query);
            $source = file_get_contents(__DIR__ . '/../../includes/Portal/U3ApprovalInbox.php');
            self::assertStringContainsString('each group displays its most recent 50', $source);
        } finally { $GLOBALS['wpdb'] = $previous; }
    }

    public function testPendingReviewRowsRetainMissingSubjectsAndKeepWorkflowLinkInsideRow(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Portal/U3ApprovalInbox.php');
        self::assertSame(3, substr_count($source, ' r LEFT JOIN '));
        self::assertStringContainsString('m.id AS subject_id', $source);
        self::assertStringContainsString('l.id AS subject_id', $source);
        self::assertStringContainsString('p.order_id AS subject_order_id', $source);
        self::assertStringContainsString('Evidence</th>', $source);
        self::assertStringContainsString('Open review evidence</a></td></tr>', $source);
    }

    public function testSubjectEvidenceDistinguishesMissingChangedAndConflictingReferences(): void
    {
        require_once __DIR__ . '/../../includes/Portal/U3ApprovalInbox.php';
        $method = new ReflectionMethod(\DigiForge\Portal\U3ApprovalInbox::class, 'subjectEvidenceState');
        $inbox = (new ReflectionClass(\DigiForge\Portal\U3ApprovalInbox::class))->newInstanceWithoutConstructor();
        self::assertSame('MISSING SUBJECT — REVIEW', $method->invoke($inbox, ['subject_id' => null]));
        self::assertSame('CONFLICTING ORDER — REVIEW', $method->invoke($inbox, ['subject_id' => 3, 'subject_order_id' => 9, 'order_id' => 4]));
        self::assertSame('SUBJECT CHANGED — RECHECK', $method->invoke($inbox, ['subject_id' => 3, 'created_at' => '2026-09-01 00:00:00', 'subject_updated_at' => '2026-09-02 00:00:00']));
        self::assertSame('RECORDED — VERIFY IN WORKFLOW', $method->invoke($inbox, ['subject_id' => 3, 'created_at' => '2026-09-02 00:00:00', 'subject_updated_at' => '2026-09-01 00:00:00']));
    }

    public function testAttentionShowsMissingSourceReferenceWithoutInventingEvidence(): void
    {
        $portal = file_get_contents(__DIR__ . '/../../includes/Portal/Portal.php');
        self::assertStringContainsString('Source evidence</th>', $portal);
        self::assertStringContainsString('MISSING SOURCE REFERENCE — REVIEW', $portal);
        self::assertStringContainsString("(int) $" . "alert['source_id'] < 1", $portal);
    }

    public function testReviewEvidenceFocusIsAllowlistedAndReportsMissingRecords(): void
    {
        $inbox = file_get_contents(__DIR__ . '/../../includes/Portal/U3ApprovalInbox.php');
        $portal = file_get_contents(__DIR__ . '/../../includes/Portal/Portal.php');
        foreach (['listing_review', 'personalization', 'pod_review', 'fulfillment_review'] as $type) {
            self::assertStringContainsString("'" . $type . "'", $inbox);
            self::assertStringContainsString("'" . $type . "'", $portal);
        }
        self::assertStringContainsString("'df_focus_id' => $" . 'reviewId', $inbox);
        self::assertStringContainsString('if ($reviewId < 1)', $inbox);
        self::assertStringContainsString("$" . 'wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $focusId)', $portal);
        self::assertStringContainsString('Requested evidence record is unavailable', $portal);
        self::assertStringContainsString('!in_array($focusId, array_map', $portal);
    }

}
