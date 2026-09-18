<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionOutcomeRepositoryStructureTest extends TestCase{
 public function testLookupIsReadOnlyAndRejectsDualTerminalState():void{
  $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionOutcomeRepository.php');self::assertIsString($s);
  foreach(['pod_execution_receipts','pod_execution_failures','digiforge_outcome_invariant',"['status'=>409]","'retry_permitted'=>false"] as $x)self::assertStringContainsString($x,$s);
  self::assertStringNotContainsString('->insert(',$s);self::assertStringNotContainsString('wp_remote_',$s);
 }
}
