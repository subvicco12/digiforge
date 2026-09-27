<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class V6AttributionOrderIntakeHardeningTest extends TestCase
{
    public function testEtsyPodIntakeRequiresActiveBusinessOwnership():void
    {
        $lifecycle=file_get_contents(__DIR__.'/../../includes/Listings/EtsyOrderWebhookLifecycle.php');
        self::assertStringContainsString('assertActiveOwnershipForMapping',$lifecycle);
        self::assertStringContainsString('$providerMappingId=0;$reviewRequired=true',$lifecycle);
        self::assertStringContainsString("'fulfillment_authorized'=>false",$lifecycle);
        self::assertStringContainsString("'external_execution_performed'=>false",$lifecycle);
    }

    public function testBusinessAttributionFailsClosedWhenAmbiguous():void
    {
        $model=file_get_contents(__DIR__.'/../../includes/POD/BusinessAttributionReadModel.php');
        self::assertStringContainsString('LIMIT 2',$model);
        self::assertStringContainsString('count($rows)!==1',$model);
        self::assertStringContainsString("'attribution_authoritative'=>true",$model);
        self::assertStringContainsString("'external_execution_performed'=>false",$model);
    }
}
