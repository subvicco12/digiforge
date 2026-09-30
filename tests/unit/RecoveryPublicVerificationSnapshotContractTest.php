<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryPublicVerificationSnapshotContractTest extends TestCase
{
 public function testSnapshotIsPublicReadOnlyAndMinimal():void {
  $c=(string)file_get_contents(__DIR__.'/../../includes/REST/Controller.php');
  foreach(["'/recovery/verification-snapshot'","'methods' => 'GET'","'permission_callback' => '__return_true'",'recovery_verification_snapshot',"'read_only'=>true","'external_actions_performed'=>false",'activation_not_authorized','automation_unarmed','no_effective_feature_switches'] as $n)self::assertStringContainsString($n,$c);
  $start=strpos($c,'public function recovery_verification_snapshot');$end=strpos($c,'public function recovery_evidence',$start);self::assertNotFalse($start);self::assertNotFalse($end);$m=substr($c,$start,$end-$start);
  foreach(['RecoveryEvidence::','RecoveryDispatchLedger::','CredentialVault','wp_remote_','update_option(','Settings::set(','nonce','secret','token'] as $n)self::assertStringNotContainsString($n,$m);
 }
 public function testVerifierNoLongerDependsOnAuthenticatedHealthOrReadinessRoutes():void {
  $v=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryStagingVerifier.php');
  self::assertStringContainsString('recovery/verification-snapshot',$v);
  self::assertStringNotContainsString("wp-json/digiforge/v1/health",$v);
  self::assertStringNotContainsString("wp-json/digiforge/v1/readiness",$v);
  self::assertStringContainsString('database-backup/marker/',$v);
 }
}
