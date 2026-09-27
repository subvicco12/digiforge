<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyProviderErrorEvidenceContractTest extends TestCase
{
 public function testEvidenceIsBoundedAndAllowlisted():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyProviderErrorEvidence.php');
  foreach(["['error','code','message']",'65536','512','wp_strip_all_tags'] as $n)self::assertStringContainsString($n,$s);
  foreach(['Authorization','access_token','refresh_token','wp_remote_'] as $n)self::assertStringNotContainsString($n,$s);
 }
 public function testFailureEvidenceFlowsWithoutRawBody():void{
  $e=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyControlledHttpExecutor.php');
  self::assertStringContainsString('EtsyProviderErrorEvidence::extract',$e);
  self::assertStringContainsString("'provider_error_evidence'=>\$providerErrorEvidence",$e);
  self::assertStringContainsString("'response_body_returned'=>false",$e);
  $l=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyAttemptLifecycleService.php');
  self::assertStringContainsString("'provider_error_evidence'=>",$l);
 }
 public function testMediaResponseIdentityFallsBackToPersistedResource():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyControlledDraftExecutionCoordinator.php');
  foreach(['resource_reference',"\$externalReference!==''?\$externalReference:\$resourceReference"] as $n)self::assertStringContainsString($n,$s);
 }
}
