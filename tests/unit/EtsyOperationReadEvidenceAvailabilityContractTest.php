<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyOperationReadEvidenceAvailabilityContractTest extends TestCase{
 public function testIdempotencyAndConfirmedOutcomeReadsExposeUnavailableEvidence():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationRepository.php');
  self::assertGreaterThanOrEqual(3,substr_count($s,"\$wpdb->last_error=''"));
  self::assertStringContainsString("'Etsy operation idempotency evidence could not be read.',503",$s);
  self::assertStringContainsString("'Confirmed CREATE_DRAFT evidence could not be read.',503",$s);
  self::assertStringContainsString("'Confirmed PUBLISH_LISTING evidence could not be read.',503",$s);
  self::assertStringContainsString("array<string,mixed>|WP_Error|null",$s);
 }
 public function testConsequentialCallersPropagateUnavailableEvidenceBeforeAbsenceHandling():void{
  foreach(['EtsyRetryOperationService.php','EtsyPreCallReplacementService.php','EtsyOperationPreparationService.php'] as $file){
   $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/'.$file);
   self::assertStringContainsString("instanceof WP_Error)return",$s);
  }
  foreach(['EtsyPublishExecutionController.php','EtsyControlledExecutionController.php'] as $file){
   $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/'.$file);
   self::assertStringContainsString("instanceof WP_Error)return",$s);
  }
 }
}
