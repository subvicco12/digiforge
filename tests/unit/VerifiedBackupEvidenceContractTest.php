<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class VerifiedBackupEvidenceContractTest extends TestCase {
 public function testBackupRequiresVerificationProvenanceAndCannotBeDeclarationOnly():void {
  $c=file_get_contents(__DIR__.'/../../includes/Operations/RecoveryEvidence.php');
  foreach(['verification_method','verified_at','verified_by','verification_status','VERIFIED'] as $v) self::assertStringContainsString($v,$c);
  self::assertStringContainsString("if (! self::truthy(\$normalized, 'retrievable')) return false;", $c);
  self::assertStringContainsString("'database_backup_retrievable' => self::backupVerified(\$backup)", $c);
 }
 public function testVerificationAuditIsExplicitlyNonAuthorizing():void {
  $c=file_get_contents(__DIR__.'/../../includes/REST/Controller.php');
  self::assertStringContainsString('recovery_database_backup_evidence_verified',$c);
  self::assertStringContainsString("'retry_permitted' => false",$c);
  self::assertStringContainsString("'external_execution_authorized' => false",$c);
 }
}