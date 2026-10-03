<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyExecutionLockEvidenceAvailabilityContractTest extends TestCase{
 public function testLockQueryFailureIsDistinctFromContentionAndPropagatesBeforeExecution():void{
  $root=dirname(__DIR__,2);
  $repo=(string)file_get_contents($root.'/includes/Listings/EtsyOperationRepository.php');
  self::assertStringContainsString('public function acquireExecutionLock(int $id): bool|WP_Error',$repo);
  self::assertStringContainsString("Etsy execution-lock evidence could not be read.",$repo);
  self::assertStringContainsString("\$wpdb->last_error='';",$repo);
  self::assertStringContainsString('$acquired===null',$repo);
  foreach(['EtsyControlledDraftExecutionCoordinator.php','EtsyControlledPublishExecutionCoordinator.php','EtsyReconciliationWorkflow.php'] as $file){
   $s=(string)file_get_contents($root.'/includes/Listings/'.$file);
   self::assertStringContainsString('$lock=$this->operations->acquireExecutionLock',$s);
   self::assertStringContainsString('if($lock instanceof WP_Error)return $lock;',$s);
   self::assertStringContainsString('if($lock!==true)return self::error(',$s);
   if(str_contains($s,'execute($transport'))self::assertLessThan(strpos($s,'execute($transport'),strpos($s,'acquireExecutionLock'),$file.' must acquire verified lock before network execution.');
  }
 }
}
