<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class NonceLedgerEvidenceAvailabilityContractTest extends TestCase {
 public function testFailedNonceReadsNeverInferUnusedOrSafeConsumption():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ExecutionNonceLedger.php');
  self::assertGreaterThanOrEqual(2,substr_count($c,'digiforge_nonce_evidence_unavailable'));
  self::assertStringContainsString('unused state is not inferred.',$c);
  self::assertStringContainsString('bool|WP_Error',$c);
  self::assertStringContainsString("'retry_permitted'=>false",$c);
  self::assertStringContainsString("'external_execution_authorized'=>false",$c);
 }
 public function testUncertainConsumeConfirmationForbidsRetry():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ExecutionNonceLedger.php');
  self::assertStringContainsString('digiforge_nonce_confirmation_unavailable',$c);
  self::assertStringContainsString('may have been consumed but confirmation is unavailable; do not retry.',$c);
 }
}
