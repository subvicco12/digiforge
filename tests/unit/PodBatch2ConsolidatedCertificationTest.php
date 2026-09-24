<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
/** Consolidated certificate for the externally locked Printify/POD Batch-2 path. */
final class PodBatch2ConsolidatedCertificationTest extends TestCase{
 public function testControlledPrintifySurfaceIsComplete():void{
  $r=dirname(__DIR__,2).'/includes/POD/';
  foreach(['PrintifyControlledExecutionPlan.php','PrintifyMutationRequest.php','PrintifyLiveTransportInterlock.php','PrintifyScopedCredentialRetriever.php','PrintifyControlledTransport.php','PrintifyExecutionAdapter.php','PrintifyReconciliationLookup.php','PrintifyReconciliationRepository.php','PrintifyReconciliationWorkflow.php','ExecutionUnknownRepository.php','ExecutionOutcomeClaimRepository.php'] as $f)self::assertFileExists($r.$f,$f);
 }
 public function testOrderAndProductionAuthorizationRemainSeparated():void{
  $r=dirname(__DIR__,2).'/includes/POD/';
  $s=(string)file_get_contents($r.'PrintifyMutationRequest.php');
  foreach(['PROVIDER_ORDER_SUBMIT','PROVIDER_PRODUCTION_AUTHORIZE'] as $x)self::assertStringContainsString($x,$s);
  $p=(string)file_get_contents($r.'PrintifyControlledExecutionPlan.php');
  foreach(['PROVIDER_ORDER_SUBMIT','PROVIDER_PRODUCTION_AUTHORIZE'] as $x)self::assertStringContainsString($x,$p);
 }
 public function testUnknownPathCannotBlindlyRetry():void{
  $r=dirname(__DIR__,2).'/includes/POD/';
  foreach(['ExecutionUnknownRepository.php','PrintifyReconciliationLookup.php','PrintifyReconciliationRepository.php'] as $f){
   $s=(string)file_get_contents($r.$f);self::assertStringNotContainsString("'retry_permitted'=>true",$s,$f);
  }
  $s=(string)file_get_contents($r.'PrintifyReconciliationRepository.php');
  foreach(['PRINTIFY_RECONCILIATION_UNRESOLVED','digiforge_printify_reconciliation_read'] as $x)self::assertStringContainsString($x,$s);
 }
 public function testReconciliationIsReadOnlyAndBounded():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyReconciliationLookup.php');
  foreach(["'method'=>'GET'",'$maxPages=10',"'pages_checked'=>"] as $x)self::assertStringContainsString($x,$s);
  foreach(["'method'=>'POST'",'sleep(','usleep(','wp_schedule_','as_schedule_'] as $x)self::assertStringNotContainsString($x,$s);
 }
 public function testPersonalizationAndReadinessFoundationsRemainPresent():void{
  $r=dirname(__DIR__,2).'/includes/POD/';
  foreach(['ReadinessGate.php','Validator.php','ProductionTemplateContract.php','ProductionTemplateRepository.php','ProviderRouter.php','ProviderRoutePreparation.php'] as $f)self::assertFileExists($r.$f,$f);
 }
}
