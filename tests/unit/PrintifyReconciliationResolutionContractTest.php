<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyReconciliationResolutionContractTest extends TestCase{
 public function testLatestResolutionDefaultsFailClosed():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyReconciliationRepository.php');
  foreach(['function latest','PRINTIFY_RECONCILIATION_UNRESOLVED',"'retry_permitted'=>false","'reconciliation_required'=>true",'ORDER BY id DESC LIMIT 1','last_error','digiforge_printify_reconciliation_read'] as $x)self::assertStringContainsString($x,$s);
 }
 public function testWorkflowResolutionRequiresPersistedUnknown():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyReconciliationWorkflow.php');
  foreach(['function resolution','ExecutionOutcomeRepository::findByAuthorizationHash',"'EXECUTION_UNKNOWN'",'PrintifyReconciliationRepository::latest'] as $x)self::assertStringContainsString($x,$s);
 }
}
