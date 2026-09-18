<?php

declare(strict_types=1);

final class ExecutionFailureRepositoryTest extends WP_UnitTestCase
{
 protected function setUp():void{
  parent::setUp();DigiForge\Core\Activator::activate();global $wpdb;
  $wpdb->query('TRUNCATE TABLE '.DigiForge\Database\Tables::pod_execution_failures());
 }
 private function failure(string $hash):array{
  return ['state'=>'EXECUTION_FAILED_RECORDED','failure_hash'=>$hash,'failure'=>[
   'action'=>'CREATE_PROVIDER_DRAFT','evidence_hash'=>str_repeat('a',64),'authorization_hash'=>str_repeat('b',64),
   'authorization_nonce_hash'=>str_repeat('c',64),'executed_by'=>7,'recorded_at'=>1700000000,
   'adapter_status'=>'FAILED','nonce_consumed'=>true,'retry_permitted'=>false,
  ]];
 }
 public function testExactReplayReturnsSameFailure():void{
  $h=str_repeat('d',64);$a=DigiForge\POD\ExecutionFailureRepository::save($this->failure($h));
  self::assertIsArray($a);$b=DigiForge\POD\ExecutionFailureRepository::save($this->failure($h));
  self::assertIsArray($b);self::assertSame((int)$a['id'],(int)$b['id']);
 }
 public function testConflictingReplayFails409():void{
  self::assertIsArray(DigiForge\POD\ExecutionFailureRepository::save($this->failure(str_repeat('d',64))));
  $x=DigiForge\POD\ExecutionFailureRepository::save($this->failure(str_repeat('e',64)));
  self::assertWPError($x);self::assertSame('digiforge_failure_conflict',$x->get_error_code());self::assertSame(409,$x->get_error_data()['status']);
 }
}
