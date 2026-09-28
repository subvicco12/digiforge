<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ConsumedPermitEvidenceAvailabilityContractTest extends TestCase {
 public function testFailedNonceReadCannotLookUnconsumed():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ConsumedPermitOutcomeReadModel.php');
  self::assertStringContainsString('consumed_permit_evidence_unavailable',$c);
  self::assertStringContainsString('Consumed permit evidence is unavailable; no execution state is inferred.',$c);
  self::assertLessThan(strpos($c,"'state'=>'PERMIT_NOT_CONSUMED'"),strpos($c,'!empty($wpdb->last_error)'));
 }
 public function testUnavailableEvidenceForbidsReplayAuthority():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ConsumedPermitOutcomeReadModel.php');
  self::assertStringContainsString("'retry_permitted'=>false",$c);
  self::assertStringContainsString("'external_execution_authorized'=>false",$c);
  self::assertStringContainsString("'external_execution_performed'=>false",$c);
 }
}
