<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class PodBusinessScopeEvidenceAvailabilityContractTest extends TestCase
{
 public function testIndependentScopeReadsClearStaleErrorsAndFailClosed():void{
  $s=(string)file_get_contents(__DIR__.'/../../includes/POD/BusinessScopeRepository.php');
  foreach(['Active POD ownership evidence could not be read.','Business scope evidence could not be read.','Store scope evidence could not be read.','Product-program scope evidence could not be read.','Provider mapping evidence could not be read.'] as $n)self::assertStringContainsString($n,$s);
  self::assertGreaterThanOrEqual(6,substr_count($s,"\$wpdb->flush()"));
 }
 public function testPostWriteReadbacksAndCommitUncertaintyDoNotInviteRetry():void{
  $s=(string)file_get_contents(__DIR__.'/../../includes/POD/BusinessScopeRepository.php');
  foreach(['digiforge_scope_confirmation_unavailable','digiforge_scope_commit_unknown',"'retry_permitted'=>false","\$committed=\$wpdb->query('COMMIT')"] as $n)self::assertStringContainsString($n,$s);
 }
 public function testSharedControllerTransactionChecksStartAndCommit():void{
  $s=(string)file_get_contents(__DIR__.'/../../includes/REST/PodController.php');
  foreach(['digiforge_mapping_transaction_unavailable','digiforge_mapping_commit_unknown',"'retry_permitted'=>false","\$started=\$wpdb->query('START TRANSACTION')","\$committed=\$wpdb->query('COMMIT')"] as $n)self::assertStringContainsString($n,$s);
 }
}
