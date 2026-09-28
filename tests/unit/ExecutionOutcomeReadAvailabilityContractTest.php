<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionOutcomeReadAvailabilityContractTest extends TestCase {
 public function testFailedTerminalReadsCannotLookLikeNoOutcome():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ExecutionOutcomeRepository.php');
  self::assertGreaterThanOrEqual(3,substr_count($c,'digiforge_outcome_evidence_unavailable'));
  self::assertStringContainsString('Execution outcome evidence is unavailable; no terminal state is inferred.',$c);
  self::assertGreaterThanOrEqual(3,substr_count($c,'!empty($wpdb->last_error)'));
 }
 public function testUnavailableOutcomeForbidsRetryAuthority():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ExecutionOutcomeRepository.php');
  self::assertGreaterThanOrEqual(3,substr_count($c,"'retry_permitted'=>false"));
  self::assertGreaterThanOrEqual(3,substr_count($c,"'external_execution_authorized'=>false"));
 }
}
