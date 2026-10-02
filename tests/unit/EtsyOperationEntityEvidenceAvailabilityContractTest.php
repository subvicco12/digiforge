<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyOperationEntityEvidenceAvailabilityContractTest extends TestCase{
 public function testEntityEvidenceFailureIsDistinctAndPropagatedAcrossDirectCallers():void{
  $root=dirname(__DIR__,2);
  $repo=(string)file_get_contents($root.'/includes/Listings/EtsyOperationRepository.php');
  self::assertStringContainsString('public function find(int $id): array|WP_Error|null',$repo);
  self::assertStringContainsString("Etsy operation entity evidence could not be read.",$repo);
  self::assertStringContainsString("\$wpdb->last_error='';",$repo);
  self::assertGreaterThanOrEqual(8,substr_count($repo,'instanceof WP_Error'));
  foreach([
   'EtsyReconciliationService.php','EtsySentTransitionService.php','EtsyReconciliationWorkflow.php',
   'EtsyReconciliationTransitionService.php','EtsyAdapterOutcomePersistenceService.php',
   'EtsyHttpReconciliationCoordinator.php','EtsyRetryOperationService.php',
   'EtsyReconciliationResultService.php','EtsyPreCallReplacementService.php',
   'EtsyControlledPublishExecutionCoordinator.php','EtsyControlledDraftExecutionCoordinator.php',
   'EtsyOperationPreparationService.php'
  ] as $file){
   $s=(string)file_get_contents($root.'/includes/Listings/'.$file);
   self::assertStringContainsString('instanceof WP_Error',$s,$file.' must propagate unavailable operation evidence.');
  }
 }
}
