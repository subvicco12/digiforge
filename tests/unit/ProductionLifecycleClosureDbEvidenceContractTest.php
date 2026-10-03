<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductionLifecycleClosureDbEvidenceContractTest extends TestCase
{
    public function testClosureReadsAndInsertRaceConfirmationIsolateDatabaseEvidence(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionLifecycleClosureRepository.php');self::assertIsString($source);
        self::assertGreaterThanOrEqual(6,substr_count($source,"\$wpdb->last_error='';"));
        self::assertStringContainsString('production_closure_evidence_unavailable',$source);
        self::assertStringContainsString('production_closure_confirmation_unavailable',$source);
        self::assertStringContainsString("'retry_permitted'=>false",$source);
        self::assertStringContainsString("'external_execution_authorized'=>false",$source);
    }
}
