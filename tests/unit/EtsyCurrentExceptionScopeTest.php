<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyCurrentExceptionScopeTest extends TestCase
{
    public function testTerminalOrdersStayVisibleAsHistoryButLeaveCurrentExceptionQueries(): void
    {
        $source=(string) file_get_contents(__DIR__.'/../../includes/Operations/EtsyOperationsSnapshot.php');
        self::assertStringContainsString("'REJECTED','SUPERSEDED','CLOSED'",$source);
        foreach (['order_lines_without_provider_mapping','order_lines_with_ambiguous_provider_mapping','approved_plans_missing_readiness_evidence'] as $key) {
            self::assertStringContainsString($key,$source);
        }
        self::assertSame(3,substr_count($source,"o.state NOT IN ('CLOSED','REJECTED','SUPERSEDED')"));
        self::assertStringContainsString("'operations_requiring_reconciliation'",$source);
        self::assertStringContainsString("'mutation_permitted'=>false",$source);
    }
}
