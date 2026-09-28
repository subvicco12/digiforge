<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PortalAiBudgetFulfillmentProvidersContractTest extends TestCase {
 public function testPortalHasBlueprintAiBudgetAndFulfillmentAreas():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['AI & Budget','Fulfillment / Providers','Orders / Personalization','Audit / Reconciliation','A prepared or approved plan is not production authorization.','this view cannot submit or retry a provider order.'] as $v) self::assertStringContainsString($v,$c);
 }
 public function testDepthProjectionIsBoundedAndNonAuthorizing():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/OperationalDepthReadModel.php');
  foreach(['shop_ai_policies','CostKpiReadModel','min(100,','fulfillment_plans',"'provider_execution_authorized']=false","'production_authorized']=false","'retry_permitted']=false"] as $v) self::assertStringContainsString($v,$c);
  self::assertStringNotContainsString('canonical_payload',$c);
  self::assertStringNotContainsString('provider_mapping_snapshot',$c);
 }
}