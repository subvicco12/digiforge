<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class OutcomeClaimInsertCertaintyContractTest extends TestCase {
 public function testClaimRequiresExactlyOneInsertAndTreatsMissingConfirmationAsUnknown():void {
  $s=(string)file_get_contents(__DIR__.'/../../includes/POD/ExecutionOutcomeClaimRepository.php');
  self::assertStringContainsString("if(\$wpdb->insert(\$table,\$row)===1)",$s);
  self::assertStringContainsString("if(!is_array(\$winner))return new WP_Error('digiforge_outcome_claim_confirmation_unavailable'",$s);
  self::assertStringContainsString("'retry_permitted'=>false,'external_execution_authorized'=>false",$s);
 }
}
