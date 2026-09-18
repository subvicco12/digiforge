<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ReadinessGateStructureTest extends TestCase
{
 public function testGateRequiresScopeRoutingQaAndCommercialEvidence():void{
  $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ReadinessGate.php');self::assertIsString($s);
  foreach(['AdminScopePolicy::resolve',"'QA_READY'","'PASS'","'artwork'","'personalization'","'mockup'","'geometry'","'READY_FOR_HUMAN_APPROVAL'","'publishing_enabled'=>false","'order_execution_enabled'=>false","evidence_hash"] as $x)self::assertStringContainsString($x,$s);
  self::assertStringNotContainsString('wp_remote_',$s);
 }
}
