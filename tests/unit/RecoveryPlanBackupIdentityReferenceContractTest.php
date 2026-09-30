<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class RecoveryPlanBackupIdentityReferenceContractTest extends TestCase {
 public function testPlanBindsIndependentRecoveryKeyToImmutableBackupIdentity():void {
  $o=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryOrchestrator.php');
  foreach(["backup_identity_operation_key","backup_identity_binding_hash",'RecoveryBackupIdentityBinding::read($backupIdentityOperationKey)',"digiforge_recovery_backup_identity_unbound"] as $v) self::assertStringContainsString($v,$o);
 }
 public function testVerifierUsesPlanBoundIdentityKeyAndBindingHash():void {
  $v=(string)file_get_contents(__DIR__.'/../../includes/Operations/RecoveryStagingVerifier.php');
  self::assertStringContainsString("\$identityOperationKey = (string)(\$plan['backup_identity_operation_key'] ?? '')",$v);
  self::assertStringContainsString('RecoveryBackupIdentityBinding::read($identityOperationKey)',$v);
  self::assertStringContainsString('RecoveryBackupIdentityMarker::read($identityOperationKey)',$v);
  self::assertStringContainsString("(\$plan['backup_identity_binding_hash'] ?? '')",$v);
  self::assertStringNotContainsString('RecoveryBackupIdentityBinding::read($operationKey)',$v);
 }
 public function testPortalRequiresSeparateBackupIdentityReference():void {
  $p=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  self::assertStringContainsString('name="backup_identity_operation_key" required',$p);
  self::assertStringContainsString("'backup_identity_operation_key' => \$backupIdentityOperationKey",$p);
 }
}
