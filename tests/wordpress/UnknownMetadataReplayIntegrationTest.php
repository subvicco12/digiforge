<?php
declare(strict_types=1);
final class UnknownMetadataReplayIntegrationTest extends WP_UnitTestCase {
 protected function setUp():void {
  parent::setUp();
  DigiForge\Core\Activator::activate();
  global $wpdb;
  foreach([DigiForge\Database\Tables::pod_execution_nonces(),DigiForge\Database\Tables::pod_execution_receipts(),DigiForge\Database\Tables::pod_execution_failures(),DigiForge\Database\Tables::pod_execution_unknowns()] as $table)
   $wpdb->query('TRUNCATE TABLE '.$table);
 }
 public function testMissingReconciliationIdentityBlocksRetryAndPreservesNonce():void {
  $approval=['state'=>'HUMAN_APPROVED','decision'=>'APPROVE','publishing_enabled'=>false,'order_execution_enabled'=>false,'evidence_hash'=>str_repeat('a',64)];
  $authorization=DigiForge\POD\ExecutionAuthorization::issue($approval,'ETSY_DRAFT_CREATE',7,'unknown_metadata_nonce_001',900);
  self::assertIsArray($authorization);
  $adapter=new class implements DigiForge\POD\ExecutionAdapter {
   public int $calls=0;
   public function execute(array $permit,array $payload):array|WP_Error {
    $this->calls++;
    return new WP_Error('provider_timeout','SECRET provider message',['network_request_attempted'=>true,'token'=>'must-not-persist']);
   }
  };
  $result=DigiForge\POD\ControlledExecutionTransaction::execute($adapter,$authorization,'ETSY_DRAFT_CREATE',str_repeat('a',64),8,time(),['safe'=>'payload']);
  self::assertWPError($result);
  self::assertSame('digiforge_transaction_reconciliation_evidence_unavailable',$result->get_error_code());
  $data=$result->get_error_data();
  self::assertFalse($data['retry_permitted']);
  self::assertTrue($data['reconciliation_required']);
  self::assertFalse($data['external_execution_authorized']);
  self::assertSame(1,$adapter->calls);
  global $wpdb;
  self::assertSame(1,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::pod_execution_nonces().' WHERE authorization_hash=%s',$authorization['authorization_hash'])));
  foreach([DigiForge\Database\Tables::pod_execution_receipts(),DigiForge\Database\Tables::pod_execution_failures(),DigiForge\Database\Tables::pod_execution_unknowns()] as $table) {
   self::assertSame(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$table.' WHERE authorization_hash=%s',$authorization['authorization_hash'])));
  }
  self::assertStringNotContainsString('SECRET',wp_json_encode($result->get_error_data()));
  self::assertStringNotContainsString('must-not-persist',wp_json_encode($result->get_error_data()));
  $replay=DigiForge\POD\ControlledExecutionTransaction::execute($adapter,$authorization,'ETSY_DRAFT_CREATE',str_repeat('a',64),8,time(),['safe'=>'payload']);
  self::assertWPError($replay);
  self::assertSame('digiforge_execution_replay',$replay->get_error_code());
  self::assertSame(1,$adapter->calls);
 }
}
