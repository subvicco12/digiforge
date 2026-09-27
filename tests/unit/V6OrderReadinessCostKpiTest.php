<?php
declare(strict_types=1);

use DigiForge\Orders\OrderReadinessProjection;
use PHPUnit\Framework\TestCase;

final class V6OrderReadinessCostKpiTest extends TestCase
{
    public function testReadinessNeverImpliesExternalFulfillmentAuthorization():void
    {
        $p=OrderReadinessProjection::project(7,'hybrid',['order_approved'=>true,'line_items_present'=>true],false);
        self::assertTrue($p['ready']);
        self::assertFalse($p['external_fulfillment_authorized']);
    }

    public function testOperationalReadModelsRemainReadOnlyAndAttributable():void
    {
        $orders=file_get_contents(__DIR__.'/../../includes/Orders/OperationsReadModel.php');
        $cost=file_get_contents(__DIR__.'/../../includes/AI/CostKpiReadModel.php');
        self::assertStringContainsString("'external_fulfillment_authorized'=>false",$orders);
        self::assertStringContainsString("'external_execution_performed'=>false",$orders);
        self::assertStringContainsString('GROUP BY shop_key,workflow,stage,model_key',$cost);
        self::assertStringContainsString("'external_execution_performed'=>false",$cost);
    }

    public function testPortalLabelsOperationalViewsReadOnly():void
    {
        $portal=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
        self::assertStringContainsString('Order readiness',$portal);
        self::assertStringContainsString('AI cost KPIs',$portal);
        self::assertGreaterThanOrEqual(2,substr_count($portal,'READ ONLY'));
    }
}
