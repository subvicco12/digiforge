<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GovernedRasterQaExecutionTest extends TestCase
{
    public function test_route_preserves_human_approval_and_external_action_boundaries(): void
    {
        $controller=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/ProductionController.php');

        self::assertStringContainsString('/rasterize-and-qa', $controller);
        self::assertStringContainsString("operation_key is required.", $controller);
        self::assertStringContainsString('new DerivedRasterService($production)', $controller);
        self::assertStringContainsString('new AutomatedQa()', $controller);
        self::assertStringContainsString("AssetStorage::absolutePath", $controller);
        self::assertStringContainsString("'QA_PASSED':'QA_FAILED'", $controller);
        self::assertStringContainsString("'approval_required']=true", $controller);
        self::assertStringContainsString("'external_action_performed']=false", $controller);
        self::assertStringNotContainsString("transition('revision',$revisionId,'APPROVED')", $controller);
    }

    public function test_existing_header_idempotency_contract_remains_fail_closed(): void
    {
        $controller=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/ProductionController.php');

        self::assertStringContainsString("get_header('Idempotency-Key')", $controller);
        self::assertStringContainsString("'missing_idempotency_key'", $controller);
        self::assertStringContainsString("hash('sha256',$operation.'|'.$key)", $controller);
        self::assertStringContainsString("reserve($storage,$operation)", $controller);
        self::assertStringContainsString("complete($storage", $controller);
    }
}
