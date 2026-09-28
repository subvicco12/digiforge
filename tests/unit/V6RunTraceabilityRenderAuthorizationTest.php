<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class V6RunTraceabilityRenderAuthorizationTest extends TestCase
{
 public function testSchemaAndRunTraceabilityAreFailClosed():void{
  $s=file_get_contents(__DIR__.'/../../includes/Database/V6OperationalSchema.php');$r=file_get_contents(__DIR__.'/../../includes/AI/ShopAiGovernanceRepository.php');
  self::assertStringContainsString('public const VERSION=21',$s);self::assertStringContainsString("run_id varchar(100) NOT NULL DEFAULT ''",$s);self::assertStringContainsString('pod_render_evidence',$s);self::assertStringContainsString('pod_authorization_packages',$s);
  self::assertStringContainsString("run_id=%s",$r);self::assertStringContainsString('EXPLICIT_RUN_CONTEXT_REQUIRED',$r);self::assertStringContainsString("'authoritative'=>\$explicit",$r);
 }
 public function testOwnershipRequiresHumanApproval():void{
  $s=file_get_contents(__DIR__.'/../../includes/POD/BusinessScopeRepository.php');$a=file_get_contents(__DIR__.'/../../includes/POD/BusinessAttributionReadModel.php');
  foreach(["approveMapping","'state'=>'APPROVED'","'approved_by'=>\$reviewer","m.state=%s","m.approved_by>0","m.approved_at IS NOT NULL"] as $n)self::assertStringContainsString($n,$s);
  self::assertStringContainsString("m.state=%s",$a);self::assertStringContainsString("m.approved_by>0",$a);
 }
 public function testRenderAndAuthorizationNeverExecuteExternally():void{
  $r=file_get_contents(__DIR__.'/../../includes/POD/RenderEvidenceRepository.php');$p=file_get_contents(__DIR__.'/../../includes/POD/ProductionAuthorizationRepository.php');
  foreach(["DETERMINISTIC","AI_ASSISTED","GENERATIVE","review_status'=>'APPROVED'","external_execution_performed'=>0"] as $n)self::assertStringContainsString($n,$r);
  foreach(["assertActiveOwnershipForMapping","APPROVED_PACKAGE","external_execution_authorized'=>0","external_execution_performed'=>0"] as $n)self::assertStringContainsString($n,$p);
  self::assertStringNotContainsString('wp_remote_',$r.$p);
 }
 public function testAttentionIncludesAllNewHumanGates():void{
  $s=file_get_contents(__DIR__.'/../../includes/Portal/AttentionReadModel.php');
  foreach(['ownership_reviews','render_reviews','authorization_package_reviews'] as $n)self::assertStringContainsString($n,$s);
 }
}
