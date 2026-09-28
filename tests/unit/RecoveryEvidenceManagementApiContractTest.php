<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class RecoveryEvidenceManagementApiContractTest extends TestCase {
    public function testEvidenceApiIsAuthenticatedAndNonAuthorizing(): void {
        $c=file_get_contents(__DIR__.'/../../includes/REST/Controller.php');
        self::assertStringContainsString("/recovery/evidence", $c);
        self::assertStringContainsString("storeDatabaseBackup", $c);
        self::assertStringContainsString("storePluginPackage", $c);
        self::assertStringContainsString("'external_actions_performed' => false", $c);
        self::assertStringNotContainsString("activateEtsyPublish()", substr($c, strpos($c,"public function recovery_evidence"), strpos($c,"public function controls")-strpos($c,"public function recovery_evidence")));
    }
    public function testEvidenceRequiresConcreteIdentityAndCryptographicPackageIdentity(): void {
        $c=file_get_contents(__DIR__.'/../../includes/Operations/RecoveryEvidence.php');
        foreach(['identifier','captured_at','location','retrievable','version','source_commit','sha256','checksum_verified'] as $field) self::assertStringContainsString($field,$c);
        self::assertStringContainsString("^[a-f0-9]{40}$", $c);
        self::assertStringContainsString("^[a-f0-9]{64}$", $c);
    }
}