<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
/** Final static safety certificate for the provider-neutral execution boundary. */
final class PodExecutionSafetyCertificateTest extends TestCase{
 public function testCriticalExecutionSafetyComponentsRemainPresentAndProviderNeutral():void{
  $root=dirname(__DIR__,2).'/includes/POD/';
  $files=['ExecutionAuthorizationVerifier.php','ExecutionNonceLedger.php','ControlledExecutionGate.php','ExecutionAdapter.php','ControlledExecutionTransaction.php','ExecutionReceiptRepository.php','ExecutionFailureRepository.php','ExecutionOutcomeRepository.php'];
  foreach($files as $file){$s=file_get_contents($root.$file);self::assertIsString($s,$file);self::assertStringNotContainsString('wp_remote_',$s,$file);}
  $tx=file_get_contents($root.'ControlledExecutionTransaction.php');
  foreach(['ADAPTER_CALL_PERMITTED',"'nonce_consumed'",'ExecutionReceiptRepository::save','ExecutionFailureRepository::save'] as $x)self::assertStringContainsString($x,$tx);
 }
}
