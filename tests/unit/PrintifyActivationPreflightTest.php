<?php
declare(strict_types=1);
use DigiForge\Launch\PrintifyActivationPreflight;
use PHPUnit\Framework\TestCase;

final class PrintifyActivationPreflightTest extends TestCase
{
    public function testReadyStateIsReadOnlyAndKeepsOrderAutomationLocked(): void
    {
        $report=PrintifyActivationPreflight::summarize([
            'stop_all'=>false,'activation_authorized'=>true,'automation_armed'=>true,
            'product_development_effective'=>true,'printify_configured'=>true,
            'printify_authorized'=>false,'printify_effective'=>false,'order_automation_effective'=>false,
        ]);
        self::assertSame('READY_FOR_CONTROLLED_PRINTIFY_ACTIVATION',$report['status']);
        self::assertFalse($report['credential_retrieval_performed']);
        self::assertFalse($report['network_requests_performed']);
        self::assertFalse($report['external_actions_performed']);
        self::assertTrue($report['checks']['order_automation_still_locked']);
    }

    public function testPreflightBlocksWhenPrerequisitesOrIsolationFail(): void
    {
        $report=PrintifyActivationPreflight::summarize([
            'stop_all'=>false,'activation_authorized'=>true,'automation_armed'=>true,
            'product_development_effective'=>false,'printify_configured'=>true,
            'printify_authorized'=>false,'printify_effective'=>false,'order_automation_effective'=>true,
        ]);
        self::assertSame('BLOCKED',$report['status']);
        self::assertContains('product_development_effective',$report['blockers']);
        self::assertContains('order_automation_still_locked',$report['blockers']);
    }
}
