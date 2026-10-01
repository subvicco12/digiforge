<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class HumanGatePortalContractTest extends TestCase{
 public function testQueueAndPortalRemainHumanGateOnly():void{$r=(string)file_get_contents(__DIR__.'/../../includes/Orders/HumanGateOperationsReadModel.php');foreach(["'external_execution_authorized'=>false","'retry_permitted'=>false","review_status NOT IN","state=%s"] as $n)self::assertStringContainsString($n,$r);$p=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');foreach(['PERSONALIZATION_REVIEW_ACTION','OWNERSHIP_REVIEW_ACTION','reviewPersonalization','approveMapping','External authority: NO','No provider or marketplace execution was authorized','manage_digiforge_orders','manage_digiforge_pod'] as $n)self::assertStringContainsString($n,$p);}
}
