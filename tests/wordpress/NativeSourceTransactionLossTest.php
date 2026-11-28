<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
use DigiForge\Connector\Printify\{NativeProtectedOptionDependencies as Roots,ShopBinding,CredentialReference};
final class NativeSourceTransactionLossTest extends WP_UnitTestCase {
 private PrintifyApiApprovalFixture $fixture;
 protected function setUp():void{parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);PortalAccessFixture::reset();DigiForge\Core\Activator::activate();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();DigiForge\Core\Settings::protectProduction();}
 protected function tearDown():void{try{global $wpdb;$wpdb->query('ROLLBACK');$this->fixture->restore();}finally{parent::tearDown();}}
 public function testCommitDuringLockedReadCannotReturnSuccessfulCallerOwnedFence():void {
  $v=PrintifyApiApprovalFixture::checked((new DigiForge\POD\ScopedProviderConnection())->verified('personalized_pod',$this->fixture->integration,'printify.connection.read'));$scope=new ShopBinding(2,'personalized_pod',$this->fixture->integration,$v['external_shop_reference'],$v['environment'],$v['review_revision'],new CredentialReference($this->fixture->integration),$v['binding_hash'],$v['expires_at']);
  global $wpdb;$key='digiforge_pp_plan_'.hash('sha256','synthetic transaction-loss fixture');$raw='synthetic data only';self::assertTrue(add_option($key,$raw,'',false));$expected=[$key=>['exists'=>true,'sha256'=>hash('sha256',$raw)]];
  self::assertNotFalse($wpdb->query('START TRANSACTION'));$control=PrintifyApiApprovalFixture::checked(Roots::lockCurrent($scope,1,$expected));self::assertTrue($control['source_data_options_locked']);self::assertFalse($control['source_approval_cas_verified']);self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'));self::assertNotFalse($wpdb->query('ROLLBACK'));
  self::assertNotFalse($wpdb->query('START TRANSACTION'));$injected=false;$hook=static function($q)use(&$injected,$wpdb){if(!$injected&&str_contains($q,'SELECT option_name,SHA2(option_value,256)')&&str_contains($q,'FOR UPDATE')){$injected=true;$wpdb->query('COMMIT');}return $q;};
  add_filter('query',$hook);try{$result=Roots::lockCurrent($scope,1,$expected);}finally{remove_filter('query',$hook);}self::assertTrue($injected);self::assertSame('0',(string)$wpdb->get_var('SELECT @@session.in_transaction'));self::assertWPError($result,'Connection/transaction loss must deny instead of returning a successful source fence.');self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }

 public function testOptionsWithoutFullUniqueNameIndexAreRejectedBeforeClaimingNarrowFences():void {
  $v=PrintifyApiApprovalFixture::checked((new DigiForge\POD\ScopedProviderConnection())->verified('personalized_pod',$this->fixture->integration,'printify.connection.read'));$scope=new ShopBinding(2,'personalized_pod',$this->fixture->integration,$v['external_shop_reference'],$v['environment'],$v['review_revision'],new CredentialReference($this->fixture->integration),$v['binding_hash'],$v['expires_at']);
  global $wpdb;$key='digiforge_pp_plan_'.hash('sha256','synthetic no-unique fixture');$raw='synthetic data only';self::assertTrue(add_option($key,$raw,'',false));$expected=[$key=>['exists'=>true,'sha256'=>hash('sha256',$raw)]];$original=$wpdb->options;$copy=$wpdb->prefix.'source_no_unique';
  self::assertNotFalse($wpdb->query('CREATE TABLE '.$copy.' LIKE '.$original));self::assertNotFalse($wpdb->query('ALTER TABLE '.$copy.' DROP INDEX option_name'));self::assertNotFalse($wpdb->query('INSERT INTO '.$copy.' SELECT * FROM '.$original));
  try{$wpdb->options=$copy;self::assertNotFalse($wpdb->query('START TRANSACTION'));$result=Roots::lockCurrent($scope,1,$expected);self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'));self::assertWPError($result,'Without a full single-column UNIQUE option_name index, an exact narrow row/gap fence cannot be certified.');}finally{$wpdb->query('ROLLBACK');$wpdb->options=$original;$wpdb->query('DROP TABLE IF EXISTS '.$copy);}
 }
}
