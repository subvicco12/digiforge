<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GovernedRevisionApprovalExecutionStructureTest extends TestCase
{
    public function testApprovalRouteIsNarrowChecksumBoundAndNonExternal(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/REST/ProductionController.php');

        self::assertStringContainsString('/production/revisions/(?P<id>\\d+)/approve', $source);
        self::assertStringContainsString("'production_revision_approve_'.\$id", $source);
        self::assertStringContainsString("'checksum_sha256'", $source);
        self::assertStringContainsString("'QA_PASSED'", $source);
        self::assertStringContainsString('hash_equals', $source);
        self::assertStringContainsString("transition('revision',\$id,'APPROVED')", $source);
        self::assertStringContainsString("'external_action_performed'=>false", $source);
        self::assertStringContainsString("'approval_required'=>false", $source);
    }

    public function testGenericStateRouteStillRequiresHeaderIdempotency(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/REST/ProductionController.php');

        self::assertStringContainsString("return \$this->mutate(\$r,'production_state_", $source);
        self::assertStringContainsString("'missing_idempotency_key'", $source);
    }
}
