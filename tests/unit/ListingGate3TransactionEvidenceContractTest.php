<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingGate3TransactionEvidenceContractTest extends TestCase{
 public function testGate3DecisionSeparatesTransactionPersistenceAndCommitUncertainty():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
  foreach(['gate3_transaction_unavailable','gate3_persistence_unavailable','gate3_commit_unknown'] as $n)self::assertStringContainsString($n,$s);
  self::assertStringContainsString("START TRANSACTION')===false||!empty(\$wpdb->last_error)",$s);
  self::assertStringContainsString("COMMIT')===false||!empty(\$wpdb->last_error)",$s);
 }
}