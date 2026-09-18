<?php

declare(strict_types=1);

final class ExecutionOutcomeRepositoryTest extends WP_UnitTestCase
{
 protected function setUp():void{
  parent::setUp();DigiForge\Core\Activator::activate();global $wpdb;
  $wpdb->query('TRUNCATE TABLE '.DigiForge\Database\Tables::pod_execution_receipts());
  $wpdb->query('TRUNCATE TABLE '.DigiForge\Database\Tables::pod_execution_failures());
 }
 public function testNotFoundOutcome():void{
  $x=DigiForge\POD\ExecutionOutcomeRepository::findByAuthorizationHash(str_repeat('a',64));
  self::assertSame('EXECUTION_OUTCOME_NOT_FOUND',$x['state']);
 }
 public function testFailureBlocksLaterSuccessWrite():void{
  global $wpdb;$auth=str_repeat('1',64);$nonce=str_repeat('2',64);$now=current_time('mysql',true);
  $wpdb->insert(DigiForge\Database\Tables::pod_execution_failures(),['action'=>'X','evidence_hash'=>str_repeat('3',64),'authorization_hash'=>$auth,'nonce_hash'=>$nonce,'failure_category'=>'adapter_error','failure_code'=>'timeout','executed_by'=>1,'recorded_at'=>$now,'failure_hash'=>str_repeat('4',64),'created_at'=>$now]);
  $receipt=['state'=>'EXECUTION_RECORDED','receipt_hash'=>str_repeat('5',64),'receipt'=>['action'=>'X','evidence_hash'=>str_repeat('3',64),'authorization_hash'=>$auth,'authorization_nonce_hash'=>$nonce,'external_reference'=>'ref-1','executed_by'=>1,'executed_at'=>1700000000]];
  $x=DigiForge\POD\ExecutionReceiptRepository::save($receipt);
  self::assertWPError($x);self::assertSame('digiforge_terminal_outcome_conflict',$x->get_error_code());self::assertSame(409,$x->get_error_data()['status']);
 }
 public function testSuccessBlocksLaterFailureWrite():void{
  global $wpdb;$auth=str_repeat('6',64);$nonce=str_repeat('7',64);$now=current_time('mysql',true);
  $wpdb->insert(DigiForge\Database\Tables::pod_execution_receipts(),['action'=>'X','evidence_hash'=>str_repeat('8',64),'authorization_hash'=>$auth,'nonce_hash'=>$nonce,'external_reference'=>'ref-2','executed_by'=>1,'executed_at'=>$now,'receipt_hash'=>str_repeat('9',64),'created_at'=>$now]);
  $failure=['state'=>'EXECUTION_FAILED_RECORDED','failure_hash'=>str_repeat('a',64),'failure'=>['action'=>'X','evidence_hash'=>str_repeat('8',64),'authorization_hash'=>$auth,'authorization_nonce_hash'=>$nonce,'executed_by'=>1,'recorded_at'=>1700000000,'adapter_status'=>'FAILED','failure_category'=>'adapter_error','failure_code'=>'timeout','nonce_consumed'=>true,'retry_permitted'=>false]];
  $x=DigiForge\POD\ExecutionFailureRepository::save($failure);
  self::assertWPError($x);self::assertSame('digiforge_terminal_outcome_conflict',$x->get_error_code());self::assertSame(409,$x->get_error_data()['status']);
 }
 public function testDualTerminalStateFailsClosed():void{
  // Corrupted contradictory terminal state must be observable and rejected.
  global $wpdb;$auth=str_repeat('b',64);$nonce=str_repeat('c',64);$now=current_time('mysql',true);
  $wpdb->insert(DigiForge\Database\Tables::pod_execution_receipts(),['action'=>'X','evidence_hash'=>str_repeat('d',64),'authorization_hash'=>$auth,'nonce_hash'=>$nonce,'external_reference'=>'ref-1','executed_by'=>1,'executed_at'=>$now,'receipt_hash'=>str_repeat('e',64),'created_at'=>$now]);
  $wpdb->insert(DigiForge\Database\Tables::pod_execution_failures(),['action'=>'X','evidence_hash'=>str_repeat('d',64),'authorization_hash'=>$auth,'nonce_hash'=>$nonce,'executed_by'=>1,'recorded_at'=>$now,'failure_hash'=>str_repeat('f',64),'created_at'=>$now]);
  $x=DigiForge\POD\ExecutionOutcomeRepository::findByAuthorizationHash($auth);
  self::assertWPError($x);self::assertSame('digiforge_outcome_invariant',$x->get_error_code());self::assertSame(409,$x->get_error_data()['status']);
 }
}
