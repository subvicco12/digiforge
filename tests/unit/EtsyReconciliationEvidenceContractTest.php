<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyReconciliationEvidenceContractTest extends TestCase
{
 public function testSchemaAddsEvidenceAdditively():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Database/EtsyOperationSchema.php');self::assertStringContainsString('VERSION = 3',$s);self::assertStringContainsString('reconciliation_evidence longtext NULL',$s);}
 public function testEvidenceIsFingerprintBoundBoundedAndImmutable():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationRepository.php');foreach(['recordReconciliationEvidence','EtsyRequestFingerprint::fromPayload','request_fingerprint','65535','reconciliation_evidence_conflict','idempotent_reconciliation_evidence'] as $n)self::assertStringContainsString($n,$s);}
 public function testEvidencePersistenceHasNoExternalSideEffects():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationRepository.php');foreach(['wp_remote_','curl_exec(','api.etsy.com','openapi.etsy.com'] as $n)self::assertStringNotContainsString($n,$s);}
}
