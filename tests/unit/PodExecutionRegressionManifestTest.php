<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
/** Guards the complete controlled-execution regression surface from accidental test loss. */
final class PodExecutionRegressionManifestTest extends TestCase{
 public function testCriticalRegressionSuitesExist():void{
  $root=dirname(__DIR__);
  $required=[
   'unit/ExecutionAdapterFailureStructureTest.php',
   'unit/ControlledExecutionTerminalPathsTest.php',
   'unit/PodExecutionSafetyCertificateTest.php',
   'wordpress/ExecutionReceiptRepositoryTest.php',
   'wordpress/ExecutionFailureRepositoryTest.php',
   'wordpress/ExecutionOutcomeRepositoryTest.php',
  ];
  foreach($required as $file)self::assertFileExists($root.'/'.$file,$file);
 }
}
