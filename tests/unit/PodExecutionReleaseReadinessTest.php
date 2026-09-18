<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
/** Release-readiness guard for the controlled POD execution subsystem. Exact-head CI certification required. */
final class PodExecutionReleaseReadinessTest extends TestCase{
 public function testSafetyAndPersistenceLayersArePresent():void{
  $pod=dirname(__DIR__,2).'/includes/POD/';
  foreach(['ExecutionAuthorizationVerifier.php','ExecutionNonceLedger.php','ControlledExecutionGate.php','ExecutionAdapter.php','ExecutionAdapterFailure.php','ControlledExecutionTransaction.php','ExecutionReceiptRepository.php','ExecutionFailureRepository.php','ExecutionOutcomeRepository.php'] as $file)self::assertFileExists($pod.$file,$file);
 }
 public function testNoCriticalExecutionClassDirectlyCallsWordPressHttp():void{
  $pod=dirname(__DIR__,2).'/includes/POD/';
  foreach(['ControlledExecutionGate.php','ControlledExecutionTransaction.php','ExecutionReceiptRepository.php','ExecutionFailureRepository.php','ExecutionOutcomeRepository.php'] as $file){
   $s=file_get_contents($pod.$file);self::assertIsString($s);self::assertStringNotContainsString('wp_remote_',$s,$file);
  }
 }
}
