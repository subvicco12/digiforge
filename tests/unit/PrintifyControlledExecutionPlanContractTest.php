<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyControlledExecutionPlanContractTest extends TestCase
{
 public function testPlannerIsV2FirstFingerprintBoundAndNonExecuting():void
 {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyControlledExecutionPlan.php');
  foreach(['PROVIDER_ROUTE_PREPARED','READY_FOR_HUMAN_APPROVAL','ProductionTemplateContract::normalize','VALIDATED','V2_FIRST','request_fingerprint',"'credential_retrieval_permitted'=>false","'network_request_permitted'=>false","'order_creation_authorized'=>false","'production_authorized'=>false","'fulfillment_authorized'=>false","'external_execution_performed'=>false"] as $n)self::assertStringContainsString($n,$s);
  foreach(['wp_remote_','curl_','CredentialVault','IntegrationRepository','PrintifyCatalogClient','sleep(','usleep('] as $n)self::assertStringNotContainsString($n,$s);
 }
 public function testPlannerAcceptsPreparationIntentsButCannotAuthorizeProviderProduction():void
 {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyControlledExecutionPlan.php');
  foreach(['PREPARE_MOCKUP','PREPARE_UPLOAD','PREPARE_ORDER','PREPARE_PERSONALIZATION'] as $n)self::assertStringContainsString($n,$s);
  self::assertStringNotContainsString("'production_authorized'=>true",$s);
  self::assertStringNotContainsString("'order_creation_authorized'=>true",$s);
 }
 public function testPersonalizationRequiresApprovedHashAndBindsItIntoFingerprintMaterial():void
 {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyControlledExecutionPlan.php');
  foreach(['personalization_review_status','personalization_payload_hash',"review!=='APPROVED'",'personalizationHash!==null'] as $n)self::assertStringContainsString($n,$s);
  self::assertStringContainsString("self::error('personalization'", $s);
 }
}
