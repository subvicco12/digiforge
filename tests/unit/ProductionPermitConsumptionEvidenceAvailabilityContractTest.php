<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductionPermitConsumptionEvidenceAvailabilityContractTest extends TestCase{
 public function testPermitConsumptionDistinguishesUnavailableEvidenceFromReplayOrMismatch():void{
  $s=(string)file_get_contents(__DIR__.'/../../includes/POD/ProductionExecutionConsumptionRepository.php');
  self::assertGreaterThanOrEqual(3,substr_count($s,"\$wpdb->last_error=''"));
  self::assertStringContainsString('production_permit_evidence_unavailable',$s);
  self::assertStringContainsString('Execution nonce evidence is unavailable; nothing was consumed.',$s);
  self::assertStringContainsString('Authorization package evidence is unavailable; nothing was consumed.',$s);
  self::assertStringContainsString("'retry_permitted'=>false",$s);
  self::assertStringContainsString("'external_execution_authorized'=>false",$s);
  self::assertStringContainsString('production_permit_consumption_commit_unknown',$s);
 }
}