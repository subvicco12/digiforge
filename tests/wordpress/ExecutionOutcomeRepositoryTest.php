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
 public function testDualTerminalStateFailsClosed():void{
  global $wpdb;$auth=str_repeat('b',64);$nonce=str_repeat('c',64);$now=current_time('mysql',true);
  $wpdb->insert(DigiForge\Database\Tables::pod_execution_receipts(),['action'=>'X','evidence_hash'=>str_repeat('d',64),'authorization_hash'=>$auth,'nonce_hash'=>$nonce,'external_reference'=>'ref-1','executed_by'=>1,'executed_at'=>$now,'receipt_hash'=>str_repeat('e',64),'created_at'=>$now]);
  $wpdb->insert(DigiForge\Database\Tables::pod_execution_failures(),['action'=>'X','evidence_hash'=>str_repeat('d',64),'authorization_hash'=>$auth,'nonce_hash'=>$nonce,'executed_by'=>1,'recorded_at'=>$now,'failure_hash'=>str_repeat('f',64),'created_at'=>$now]);
  $x=DigiForge\POD\ExecutionOutcomeRepository::findByAuthorizationHash($auth);
  self::assertWPError($x);self::assertSame('digiforge_outcome_invariant',$x->get_error_code());self::assertSame(409,$x->get_error_data()['status']);
 }
}
