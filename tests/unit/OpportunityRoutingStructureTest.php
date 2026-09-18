<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OpportunityRoutingStructureTest extends TestCase
{
    public function testRoutingIsApprovalGatedAndNonExecuting(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/OpportunityRouting.php');
        self::assertIsString($source);
        self::assertStringContainsString("'APPROVE_FOR_DEVELOPMENT'",$source);
        self::assertStringContainsString('SupplierScoring::evaluate',$source);
        self::assertStringContainsString('ProductionTemplateContract::normalize',$source);
        self::assertStringContainsString("'SUPPLIER_REVIEW_REQUIRED'",$source);
        self::assertStringContainsString("'QA_READY'",$source);
        self::assertStringContainsString("'ready_for_qa'=>true",$source);
        self::assertStringNotContainsString('wp_remote_',$source);
        self::assertStringNotContainsString('publish',$source);
        self::assertStringNotContainsString('order',$source);
    }

    public function testRoutingRequiresTemplateSupplierToMatchSelectedProvider(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/OpportunityRouting.php');
        self::assertStringContainsString('digiforge_template_supplier_mismatch',$source);
        self::assertStringContainsString("['GEOMETRY_LOCKED','SAMPLE_REQUIRED','VALIDATED']",$source);
    }
}
