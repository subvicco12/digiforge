<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PersonalizedPodClosureSafetyContractTest extends TestCase {
 public function testRenderAndAuthorizationRepositoriesContainNoProviderTransport():void {
  foreach(['RenderEvidenceRepository.php','ProductionAuthorizationRepository.php'] as $file){
   $c=(string)file_get_contents(__DIR__.'/../../includes/POD/'.$file);
   foreach(['wp_remote_','PrintifyClient','ProviderHttpClient','->execute(','->dispatch('] as $needle) self::assertStringNotContainsString($needle,$c,$file.' must remain evidence/review only');
  }
 }
 public function testAuthorizationPackageRequiresCurrentReadinessHumanReviewedRenderAndOwnership():void {
  $c=(string)file_get_contents(__DIR__.'/../../includes/POD/ProductionAuthorizationRepository.php');
  foreach(['readiness($orderId)',"if(empty(\$readiness['ready']))","review_status=%s AND reviewed_by>0 AND reviewed_at IS NOT NULL AND external_execution_performed=0",'assertActiveOwnershipForMapping',"state'=>'REVIEW_REQUIRED'","external_execution_authorized'=>0","external_execution_performed'=>0"] as $needle) self::assertStringContainsString($needle,$c);
 }
 public function testHumanPackageApprovalStillCannotAuthorizeOrPerformProduction():void {
  $c=(string)file_get_contents(__DIR__.'/../../includes/POD/ProductionAuthorizationRepository.php');
  self::assertStringContainsString("'state'=>'APPROVED_PACKAGE'",$c);
  self::assertStringContainsString("['id'=>\$id,'state'=>'REVIEW_REQUIRED','external_execution_authorized'=>0,'external_execution_performed'=>0]",$c);
  self::assertStringNotContainsString("'external_execution_authorized'=>1",$c);
  self::assertStringNotContainsString("'external_execution_performed'=>1",$c);
 }
 public function testFinalConvergenceScenarioExercisesPersonalizedPodBoundary():void {
  $c=(string)file_get_contents(__DIR__.'/../wordpress/FinalConvergenceScenarioTest.php');
  foreach(['testPersonalizedPodReviewChainStopsBeforeExternalExecution','RenderEvidenceRepository','ProductionAuthorizationRepository','APPROVED_PACKAGE','external_execution_authorized']) as $needle) self::assertStringContainsString($needle,$c);
 }
}
