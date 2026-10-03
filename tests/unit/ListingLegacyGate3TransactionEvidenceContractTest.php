<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingLegacyGate3TransactionEvidenceContractTest extends TestCase{
 public function testLegacyRepairSeparatesTransactionWriteAndCommitUncertainty():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
  foreach(['legacy_gate3_transaction_unavailable','legacy_gate3_persistence_unavailable','legacy_gate3_commit_unknown'] as $n)self::assertStringContainsString($n,$s);
  self::assertStringContainsString("COMMIT')===false||!empty(\$wpdb->last_error)",$s);
 }
}