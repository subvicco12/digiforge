<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GovernedCatalogRollbackEvidenceContractTest extends TestCase
{
    public function testRollbackFailureRequiresReconciliationBeforeRetry(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/GovernedCatalogRepository.php');
        self::assertIsString($source);
        self::assertSame(3,substr_count($source,"$rollback=$wpdb->query('ROLLBACK')"));
        self::assertStringContainsString('catalog_rollback_unknown',$source);
        self::assertStringContainsString("'retry_permitted'=>false",$source);
        self::assertStringContainsString("'external_execution_authorized'=>false",$source);
        self::assertStringContainsString('reconcile durable state before retry',$source);
    }
}
