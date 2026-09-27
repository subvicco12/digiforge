<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class V6LifecycleAttentionDenominatorTest extends TestCase
{
    public function testLifecycleDenominatorsDoNotClaimAiCostAttribution():void
    {
        $model=file_get_contents(__DIR__.'/../../includes/AI/LifecycleDenominatorReadModel.php');
        self::assertStringContainsString("'denominators_authoritative'=>true",$model);
        self::assertStringContainsString("'ai_cost_attribution_established'=>false",$model);
        self::assertStringContainsString("'external_execution_performed'=>false",$model);
    }

    public function testAttentionIncludesOrdersNeedingReconciliation():void
    {
        $model=file_get_contents(__DIR__.'/../../includes/Portal/AttentionReadModel.php');
        self::assertStringContainsString("'orders_needing_reconciliation'",$model);
        self::assertStringContainsString("validation_status<>'VALIDATED'",$model);
        self::assertStringContainsString('provider_mapping_id=0',$model);
    }

    public function testFinancePortalLoadsDenominatorsReadOnly():void
    {
        $portal=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
        self::assertStringContainsString('LifecycleDenominatorReadModel',$portal);
        self::assertStringContainsString('$denominators=', $portal);
    }
}
