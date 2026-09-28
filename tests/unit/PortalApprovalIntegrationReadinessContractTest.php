<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PortalApprovalIntegrationReadinessContractTest extends TestCase {
 public function testIntegrationReadinessIsStoredEvidenceOnly():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/IntegrationReadinessReadModel.php');
  foreach(['SELECT id,provider,environment,connection_key,display_name,status,enabled,updated_at',"'connectivity_test_performed'=>false","'credentials_exposed'=>false","'external_execution_authorized'=>false"] as $v)self::assertStringContainsString($v,$c);
  foreach(['wp_remote_get','wp_remote_post','ConnectionTester'] as $v)self::assertStringNotContainsString($v,$c);
 }
 public function testPortalMakesIntegrationAuthorityBoundaryExplicit():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['opening this page never performs a provider connectivity test','Connected evidence','Needs attention','External execution authority</span><b>NO'] as $v)self::assertStringContainsString($v,$c);
 }
 public function testApprovalAggregationNeverInfersAuthority():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/U3ApprovalInbox.php');
  foreach(['Downstream pending decisions','Approval authority in this aggregate','External execution authority','NO INFERRED APPROVAL','No approval is inferred from this aggregation view'] as $v)self::assertStringContainsString($v,$c);
 }
}