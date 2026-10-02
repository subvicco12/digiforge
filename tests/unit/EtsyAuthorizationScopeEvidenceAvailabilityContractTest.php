<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyAuthorizationScopeEvidenceAvailabilityContractTest extends TestCase{
 public function testPublishGateSeparatesUnavailableScopeEvidenceFromInvalidScope():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyPublishAuthorizationGate.php');
  self::assertGreaterThanOrEqual(3,substr_count($s,"\$wpdb->last_error=''"));
  self::assertGreaterThanOrEqual(3,substr_count($s,"'evidence_unavailable'"));
  self::assertStringContainsString("int \$status=409",$s);
  self::assertStringContainsString("['status'=>\$status]",$s);
  self::assertStringContainsString("'Persisted publish scope is incomplete.'",$s);
 }
 public function testOperationPreparationSeparatesUnavailableScopeEvidenceFromApprovalDenial():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationPreparationService.php');
  self::assertGreaterThanOrEqual(3,substr_count($s,"\$wpdb->last_error=''"));
  self::assertGreaterThanOrEqual(3,substr_count($s,"'scope_evidence_unavailable'"));
  self::assertGreaterThanOrEqual(3,substr_count($s,",503)"));
  foreach(["'intent_not_approved'","'package_not_approved'","'listing_not_approved'"] as $n)self::assertStringContainsString($n,$s);
 }
 public function testScopeEvidenceHardeningRemainsLocalOnly():void{
  $combined=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyPublishAuthorizationGate.php').(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationPreparationService.php');
  foreach(['wp_remote_','curl_exec('] as $n)self::assertStringNotContainsString($n,$combined);
 }
}
