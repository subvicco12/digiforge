<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class V6LifecycleAttentionOutcomeConsistencyTest extends TestCase{
 public function testAllTerminalRepositoriesRequireConsumedNonceEvidence():void{
  foreach(['ExecutionReceiptRepository.php'=>'digiforge_receipt_nonce_evidence','ExecutionFailureRepository.php'=>'digiforge_failure_nonce_evidence','ExecutionUnknownRepository.php'=>'digiforge_unknown_nonce_evidence'] as $file=>$error){$s=file_get_contents(__DIR__.'/../../includes/POD/'.$file);self::assertStringContainsString('pod_execution_nonces',$s);self::assertStringContainsString($error,$s);}
 }
 public function testAttentionProjectionExposesLifecycleAndReadOnlyState():void{
  $s=file_get_contents(__DIR__.'/../../includes/Portal/AttentionReadModel.php');foreach(['production_lifecycle_closures','execution_outcomes_pending',"'external_execution_state'=>'READ_ONLY_NO_EXECUTION'","'external_execution_performed'=>null"] as $n)self::assertStringContainsString($n,$s);
 }
}
