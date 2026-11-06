<?php
final class RateLedgerTest extends WP_UnitTestCase {
 private function slot():mixed {$method=new ReflectionMethod(\DigiForge\POD\PrintifyCatalogReadRepository::class,'reserveAccountRate');return $method->invoke(new \DigiForge\POD\PrintifyCatalogReadRepository(),['binding'=>['shop_key'=>'personalized_pod'],'grant'=>['integration_id'=>7],'sha256'=>str_repeat('b',64)]);}
 protected function setUp():void{parent::setUp();\DigiForge\POD\PrintifyConnectionBoundary::$deny=false;global $wpdb;$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('df_catalog_rate_').'%'));$wpdb->delete(\DigiForge\Database\Tables::audit_log(),['event_type'=>'catalog_read_rate_reserved']);}
 public function testFiftyDurableSlotsThenNoFiftyFirstDispatch():void{
  for($i=0;$i<50;$i++)self::assertTrue($this->slot());
  self::assertSame('catalog_read_account_rate_limit',$this->slot()->get_error_code());
  global $wpdb;self::assertSame('50',(string)$wpdb->get_var("SELECT COUNT(*) FROM ".\DigiForge\Database\Tables::audit_log()." WHERE event_type='catalog_read_rate_reserved'"));
 }
 public function testMissingAuditCannotBecomeNewRateAuthority():void{
  self::assertTrue($this->slot());global $wpdb;$wpdb->delete(\DigiForge\Database\Tables::audit_log(),['event_type'=>'catalog_read_rate_reserved']);self::assertSame('catalog_read_rate_evidence',$this->slot()->get_error_code());
 }
 public function testScopeFailureCreatesNoLedgerSlot():void{
  \DigiForge\POD\PrintifyConnectionBoundary::$deny=true;self::assertSame('synthetic_scope_denied',$this->slot()->get_error_code());global $wpdb;self::assertSame('0',(string)$wpdb->get_var("SELECT COUNT(*) FROM ".\DigiForge\Database\Tables::audit_log()." WHERE event_type='catalog_read_rate_reserved'"));
 }
}
