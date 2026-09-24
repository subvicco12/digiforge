<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PodExecutionOutcomeClaimContractTest extends TestCase{
 public function testSchemaHasOneAuthorizationClaimAcrossOutcomeClasses():void{
  $t=(string)file_get_contents(dirname(__DIR__,2).'/includes/Database/Tables.php');$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Database/PodSchema.php');
  self::assertStringContainsString('pod_execution_outcomes',$t);self::assertStringContainsString('UNIQUE KEY authorization_hash (authorization_hash)',$s);
 }
 public function testAllOutcomeRepositoriesClaimBeforeClassSpecificInsert():void{
  foreach(['ExecutionReceiptRepository.php'=>'SUCCEEDED','ExecutionFailureRepository.php'=>'FAILED','ExecutionUnknownRepository.php'=>'UNKNOWN'] as $file=>$type){
   $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/'.$file);self::assertStringContainsString("ExecutionOutcomeClaimRepository::claim(\$auth,'".$type."'",$s);
   self::assertLessThan(strpos($s,'$wpdb->insert($table,$row)'),strpos($s,'ExecutionOutcomeClaimRepository::claim'));
  }
 }
 public function testClaimReplayRequiresExactTypeAndHash():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionOutcomeClaimRepository.php');
  self::assertStringContainsString("hash_equals((string)\$winner['outcome_hash'],\$outcomeHash)",$s);self::assertStringContainsString("hash_equals((string)\$winner['outcome_type'],\$outcomeType)",$s);
 }
}
