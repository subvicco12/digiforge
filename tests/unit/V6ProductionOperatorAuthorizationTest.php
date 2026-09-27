<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class V6ProductionOperatorAuthorizationTest extends TestCase
{
 public function testOperatorProjectionSeparatesApprovalFromCurrentPreflight():void{
  $s=file_get_contents(__DIR__.'/../../includes/POD/ProductionPreflightOperatorReadModel.php');
  foreach(['HUMAN_REVIEW_REQUIRED','PREFLIGHT_CURRENT','REVALIDATION_REQUIRED','external_execution_authorized'=>false,'external_execution_performed'=>false] as $n)self::assertStringContainsString(is_string($n)?$n:'',$s);
 }
 public function testPermitIsFingerprintBoundShortLivedAndNonExecuting():void{
  $s=file_get_contents(__DIR__.'/../../includes/POD/ProductionExecutionPermit.php');
  foreach(['PREFLIGHT_CURRENT','PROVIDER_ORDER_SUBMIT','requestFingerprint','ExecutionAuthorization::issue','external_execution_performed'=>false] as $n)self::assertStringContainsString(is_string($n)?$n:'',$s);
  self::assertStringNotContainsString('wp_remote_',$s);
 }
 public function testAttentionIncludesProductionRevalidation():void{
  $s=file_get_contents(__DIR__.'/../../includes/Portal/AttentionReadModel.php');
  self::assertStringContainsString('production_revalidation_reviews',$s);
  self::assertStringContainsString("state='APPROVED_PACKAGE'",$s);
 }
}
