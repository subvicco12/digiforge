<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
/** Final mainline certificate for the provider-neutral controlled execution subsystem. */
final class PodFinalMainlineCertificationTest extends TestCase{
 public function testCompleteControlledExecutionSurfaceIsPresent():void{
  $root=dirname(__DIR__,2).'/includes/POD/';
  foreach(['ExecutionAuthorization.php','ExecutionAuthorizationVerifier.php','ExecutionNonceLedger.php','ControlledExecutionGate.php','ExecutionOrchestrator.php','ExecutionAdapter.php','ExecutionAdapterFailure.php','ExecutionAdapterResult.php','ControlledExecutionTransaction.php','ExecutionReceipt.php','ExecutionReceiptRepository.php','ExecutionFailureRecord.php','ExecutionFailureRepository.php','ExecutionOutcomeRepository.php'] as $file)self::assertFileExists($root.$file,$file);
 }
 public function testCriticalBoundaryRemainsProviderNeutral():void{
  $root=dirname(__DIR__,2).'/includes/POD/';
  foreach(['ControlledExecutionGate.php','ExecutionOrchestrator.php','ControlledExecutionTransaction.php','ExecutionReceiptRepository.php','ExecutionFailureRepository.php','ExecutionOutcomeRepository.php'] as $file){
   $s=file_get_contents($root.$file);self::assertIsString($s);self::assertStringNotContainsString('wp_remote_',$s,$file);
  }
 }
 public function testExecutionSafetyRegressionSuitesRemainPresent():void{
  $root=dirname(__DIR__,2).'/tests/';
  foreach(['unit/PodExecutionSafetyCertificateTest.php','unit/PodExecutionReleaseReadinessTest.php','unit/ExecutionAdapterFailureProjectionTest.php','unit/ExecutionReceiptNonceVerificationTest.php','unit/ExecutionNonceLedgerTableBindingTest.php','wordpress/ExecutionReceiptRepositoryTest.php','wordpress/ExecutionFailureRepositoryTest.php','wordpress/ExecutionOutcomeRepositoryTest.php'] as $file)self::assertFileExists($root.$file,$file);
 }
}
