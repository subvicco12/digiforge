<?php
declare(strict_types=1);
final class TerminalOutcomeConsistencyTest extends WP_UnitTestCase{
 protected function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));}
 public function testFailureRequiresExactConsumedNonceAndBlocksConflictingSuccess():void{
  $auth=hash('sha256','terminal-auth');$nonce='terminal_nonce_12345678901234567890';$nonceHash=hash('sha256',$nonce);$now=time();$failure=['state'=>'EXECUTION_FAILED_RECORDED','failure_hash'=>hash('sha256','failure'),'failure'=>['action'=>'X','evidence_hash'=>hash('sha256','e'),'authorization_hash'=>$auth,'authorization_nonce_hash'=>$nonceHash,'failure_category'=>'adapter_error','failure_code'=>'timeout','executed_by'=>get_current_user_id(),'recorded_at'=>$now,'nonce_consumed'=>true,'retry_permitted'=>false]];
  $missing=DigiForge\POD\ExecutionFailureRepository::save($failure);self::assertWPError($missing);self::assertSame('digiforge_failure_nonce_evidence',$missing->get_error_code());
  self::assertTrue(DigiForge\POD\ExecutionNonceLedger::consume($nonce,$auth,get_current_user_id()));$saved=DigiForge\POD\ExecutionFailureRepository::save($failure);self::assertFalse(is_wp_error($saved));
  $receipt=['state'=>'EXECUTION_RECORDED','receipt_hash'=>hash('sha256','receipt'),'receipt'=>['action'=>'X','evidence_hash'=>hash('sha256','e'),'authorization_hash'=>$auth,'authorization_nonce_hash'=>$nonceHash,'external_reference'=>'provider-1','executed_by'=>get_current_user_id(),'executed_at'=>$now]];
  $conflict=DigiForge\POD\ExecutionReceiptRepository::save($receipt);self::assertWPError($conflict);self::assertSame('digiforge_terminal_outcome_conflict',$conflict->get_error_code());
 }
 public function testSuccessRequiresExactConsumedNonce():void{
  $auth=hash('sha256','success-auth');$nonce='success_nonce_12345678901234567890';$nonceHash=hash('sha256',$nonce);$now=time();$receipt=['state'=>'EXECUTION_RECORDED','receipt_hash'=>hash('sha256','success-receipt'),'receipt'=>['action'=>'X','evidence_hash'=>hash('sha256','success-e'),'authorization_hash'=>$auth,'authorization_nonce_hash'=>$nonceHash,'external_reference'=>'provider-2','executed_by'=>get_current_user_id(),'executed_at'=>$now]];
  $missing=DigiForge\POD\ExecutionReceiptRepository::save($receipt);self::assertWPError($missing);self::assertSame('digiforge_receipt_nonce_evidence',$missing->get_error_code());self::assertTrue(DigiForge\POD\ExecutionNonceLedger::consume($nonce,$auth,get_current_user_id()));self::assertFalse(is_wp_error(DigiForge\POD\ExecutionReceiptRepository::save($receipt)));
 }
}
