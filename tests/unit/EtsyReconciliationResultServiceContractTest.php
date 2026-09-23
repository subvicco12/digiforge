<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyReconciliationResultServiceContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationResultService.php');
    }

    public function testReconciliationAndReconciledStatesAreAccepted(): void
    {
        $s=$this->source();
        self::assertStringContainsString('EtsyOperationLifecycle::RECONCILIATION',$s);
        self::assertStringContainsString('EtsyOperationLifecycle::RECONCILED',$s);
        self::assertStringContainsString('EtsyAdapterOutcome::normalize',$s);
    }

    public function testConclusiveResultCanResumeFromReconciled(): void
    {
        $s=$this->source();
        self::assertStringContainsString("if (\$current === EtsyOperationLifecycle::RECONCILIATION)",$s);
        self::assertStringContainsString('transition($operationId,$state,$externalReference)',$s);
        self::assertStringContainsString('reconciled_requires_conclusive',$s);
    }

    public function testUnknownReturnsToUnknownOnlyBeforeReconciled(): void
    {
        $s=$this->source();
        self::assertStringContainsString('EtsyOperationLifecycle::UNKNOWN',$s);
        self::assertStringContainsString("'external_retry_permitted'=>false",$s);
    }

    public function testNoProviderCallOrExternalExecutionPrimitive(): void
    {
        $s=$this->source();
        foreach(['->execute(','wp_remote_','curl_exec(','api.etsy','etsy.com'] as $needle) self::assertStringNotContainsString($needle,$s);
    }
}
