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

    public function testPendingCountQueryFailureDoesNotReportZero(): void
    {
        require_once __DIR__ . '/../../includes/Portal/U3ApprovalInbox.php';
        require_once __DIR__ . '/../../includes/Database/Tables.php';
        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $last_error = 'fixture unavailable';
            public function get_var(string $sql): ?string { return null; }
        };
        try {
            $inbox = (new ReflectionClass(\DigiForge\Portal\U3ApprovalInbox::class))->newInstanceWithoutConstructor();
            self::assertNull((new ReflectionMethod($inbox, 'pendingOperationalCount'))->invoke($inbox));
        } finally { $GLOBALS['wpdb'] = $previous; }
    }

    public function testProductEvidenceQueryFailureDoesNotReportAnEmptyInbox(): void
    {
        require_once __DIR__ . '/../../includes/Portal/U3ApprovalInbox.php';
        require_once __DIR__ . '/../../includes/Database/Tables.php';
        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $last_error = 'fixture unavailable';
            public function get_var(string $sql): ?string { return null; }
            public function get_results(string $sql,mixed $format): ?array { return null; }
        };
        try {
            $inbox = (new ReflectionClass(\DigiForge\Portal\U3ApprovalInbox::class))->newInstanceWithoutConstructor();
            self::assertNull((new ReflectionMethod($inbox, 'pendingProductCount'))->invoke($inbox));
            self::assertNull((new ReflectionMethod($inbox, 'pendingProducts'))->invoke($inbox));
        } finally { $GLOBALS['wpdb'] = $previous; }
    }

    public function testProductApprovalCountAndEvidenceWindowRepresentUniquePlans(): void
    {
        require_once __DIR__ . '/../../includes/Portal/U3ApprovalInbox.php';
        require_once __DIR__ . '/../../includes/Database/Tables.php';
        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $countQuery = '';
            public string $rowsQuery = '';
            public function get_var(string $query): int { $this->countQuery = $query; return 73; }
            public function get_results(string $query, mixed $format): array { $this->rowsQuery = $query; return []; }
        };
        try {
            $inbox = (new ReflectionClass(\DigiForge\Portal\U3ApprovalInbox::class))->newInstanceWithoutConstructor();
            self::assertSame(73, (new ReflectionMethod($inbox, 'pendingProductCount'))->invoke($inbox));
            self::assertSame([], (new ReflectionMethod($inbox, 'pendingProducts'))->invoke($inbox));
            self::assertStringContainsString("pp.state='REVIEW_REQUIRED'", $GLOBALS['wpdb']->countQuery);
            self::assertStringNotContainsString('LIMIT', $GLOBALS['wpdb']->countQuery);
            self::assertStringNotContainsString('release_bundles', $GLOBALS['wpdb']->countQuery);
            self::assertStringContainsString('rb.id=(SELECT rb2.id', $GLOBALS['wpdb']->rowsQuery);
            self::assertStringContainsString('rb2.production_plan_id=pp.id ORDER BY rb2.id DESC LIMIT 1', $GLOBALS['wpdb']->rowsQuery);
            self::assertStringContainsString('ORDER BY pp.id DESC LIMIT 50', $GLOBALS['wpdb']->rowsQuery);
            $source = file_get_contents(__DIR__ . '/../../includes/Portal/U3ApprovalInbox.php');
            self::assertStringContainsString('this panel displays the most recent 50', $source);
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

    public function testGate2NestedEvidenceReadFailureFailsTheWholeInboxClosed(): void
    {
        require_once __DIR__ . '/../../includes/Portal/U3ApprovalInbox.php';
        require_once __DIR__ . '/../../includes/Database/Tables.php';
        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public function prepare(string $sql,mixed ...$args): string { return $sql; }
            public function get_results(string $sql,mixed $format): ?array {
                if (str_contains($sql,'production_plans pp')) return [['plan_id'=>9,'product_version_id'=>4,'channel'=>'digital','plan_state'=>'REVIEW_REQUIRED','version_label'=>'v1','product_id'=>3,'product_name'=>'Test','bundle_id'=>1,'bundle_state'=>'RELEASE_READY']];
                if (str_contains($sql,'production_plan_assets')) { $this->last_error='asset evidence unavailable'; return null; }
                return [];
            }
            public function get_var(string $sql): ?string { $this->last_error=''; return '1'; }
        };
        try {
            $inbox = (new ReflectionClass(\DigiForge\Portal\U3ApprovalInbox::class))->newInstanceWithoutConstructor();
            self::assertNull((new ReflectionMethod($inbox, 'pendingProducts'))->invoke($inbox));
        } finally { $GLOBALS['wpdb'] = $previous; }
    }

    public function testGate2CountReadFailureCannotBecomeZeroQaEvidence(): void
    {
        require_once __DIR__ . '/../../includes/Portal/U3ApprovalInbox.php';
        require_once __DIR__ . '/../../includes/Database/Tables.php';
        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $last_error = 'count unavailable';
            public function get_var(string $sql): ?string { return null; }
        };
        try {
            $inbox = (new ReflectionClass(\DigiForge\Portal\U3ApprovalInbox::class))->newInstanceWithoutConstructor();
            self::assertNull((new ReflectionMethod($inbox, 'safeCount'))->invoke($inbox, 'SELECT COUNT(*)'));
        } finally { $GLOBALS['wpdb'] = $previous; }
    }

    public function testGate2IndependentCountsIgnoreStaleErrorsButFailOnCurrentErrors(): void
    {
        require_once __DIR__ . '/../../includes/Portal/U3ApprovalInbox.php';
        require_once __DIR__ . '/../../includes/Database/Tables.php';
        $previous = $GLOBALS['wpdb'] ?? null;
        $db = new class {
            public string $prefix='wp_';
            public string $last_error='stale';
            public int $calls=0;
            public function get_var(string $sql): ?string {
                $this->calls++;
                if($this->calls===3){$this->last_error='current count failed';return null;}
                return $this->calls===1?'4':'2';
            }
        };
        $GLOBALS['wpdb']=$db;
        try {
            $inbox=(new ReflectionClass(\DigiForge\Portal\U3ApprovalInbox::class))->newInstanceWithoutConstructor();
            self::assertSame(4,(new ReflectionMethod($inbox,'pendingProductCount'))->invoke($inbox));
            $db->last_error='stale from prior query';
            self::assertSame(2,(new ReflectionMethod($inbox,'safeCount'))->invoke($inbox,'SELECT COUNT(*)'));
            self::assertNull((new ReflectionMethod($inbox,'safeCount'))->invoke($inbox,'SELECT COUNT(*)'));
        } finally {$GLOBALS['wpdb']=$previous;}
    }


}
