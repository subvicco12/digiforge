<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class OutcomeClaimConfirmationAvailabilityContractTest extends TestCase {
 public function testFailedWinnerReadIsUncertainNotConflict():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ExecutionOutcomeClaimRepository.php');
  self::assertStringContainsString('digiforge_outcome_claim_confirmation_unavailable',$c);
  self::assertStringContainsString('Execution outcome claim result is uncertain; reconcile before any retry.',$c);
  self::assertStringContainsString('!empty($wpdb->last_error)',$c);
  self::assertLessThan(strpos($c,'digiforge_outcome_claim_conflict'),strpos($c,'digiforge_outcome_claim_confirmation_unavailable'));
 }
 public function testUncertainClaimForbidsRetryAndExecution():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ExecutionOutcomeClaimRepository.php');
  self::assertStringContainsString("'retry_permitted'=>false",$c);
  self::assertStringContainsString("'external_execution_authorized'=>false",$c);
 }
 public function testWinnerConfirmationClearsStaleDatabaseError():void{$c=(string)file_get_contents(__DIR__.'/../../includes/POD/ExecutionOutcomeClaimRepository.php');self::assertStringContainsString("\$wpdb->last_error='';\$winner=\$wpdb->get_row",$c);}
}
