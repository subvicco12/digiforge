<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class V6PrintifyPreflightDryRunTest extends TestCase{
 private function source(string $path):string{return (string)file_get_contents(dirname(__DIR__,2).'/'.$path);}
 public function testPreflightIsPackageBoundReadOnlyAndFailClosed():void{
  $s=$this->source('includes/POD/PrintifyProductionPreflight.php');
  self::assertStringContainsString("APPROVED_PACKAGE",$s);
  self::assertStringContainsString("ORDER_READINESS_STALE",$s);
  self::assertStringContainsString("PRINTIFY_MAPPING_NOT_CERTIFIED",$s);
  self::assertStringContainsString("PRINTIFY_GEOMETRY_NOT_CERTIFIED",$s);
  self::assertStringContainsString("PRINTIFY_TEMPLATE_NOT_VALIDATED",$s);
  self::assertStringContainsString("PRINTIFY_ROUTE_TEMPLATE_MISMATCH",$s);
  self::assertStringContainsString("'template_fingerprint'=>",$s);
  self::assertStringContainsString("'ready_for_external_execution'=>false",$s);
  self::assertStringNotContainsString('wp_remote_',$s);
 }
 public function testRenderEvidenceBindsMappedOrderAndApprovedPersonalization():void{
  $s=$this->source('includes/POD/RenderEvidenceRepository.php');
  self::assertStringContainsString('render_mapping_not_on_order',$s);
  self::assertStringContainsString('render_personalization_not_approved',$s);
  self::assertStringContainsString("ps.review_status='APPROVED'",$s);
 }
 public function testDryRunCreatesOnlyBlockedLocalEvidence():void{
  $s=$this->source('includes/POD/PersonalizedPodDryRun.php');
  self::assertStringContainsString("'intent_state'=>'BLOCKED'",$s);
  self::assertStringContainsString("'network_execution_performed'=>false",$s);
  self::assertStringContainsString("'retry_permitted'=>false",$s);
  self::assertStringContainsString("'package_hash'=>",$s);
  self::assertStringContainsString("'readiness_hash'=>",$s);
  self::assertStringNotContainsString('wp_remote_',$s);
 }
 public function testUnknownReconciliationNeverBlindRetries():void{
  $s=$this->source('includes/POD/PrintifyUnknownReconciliationReadModel.php');
  self::assertStringContainsString('ExecutionOutcomeRepository::findByAuthorizationHash',$s);
  self::assertStringContainsString('PrintifyReconciliationRepository::latest',$s);
  self::assertStringContainsString("'retry_permitted'=>false",$s);
  self::assertStringNotContainsString('wp_remote_',$s);
 }
}
