<?php
declare(strict_types=1);
use DigiForge\POD\PrintifyAccountQuota;
final class PrintifyAccountQuotaTest extends WP_UnitTestCase
{
 private array $scope;
 public function setUp():void {parent::setUp();global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');$this->scope=['external_account_reference'=>'fixture-'.wp_generate_uuid4()];}
 public function tearDown():void {global $wpdb;$account=hash('sha256',$this->scope['external_account_reference']);$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",'%'.$wpdb->esc_like($account).'%'));parent::tearDown();}
 public function testAccountSharesQuotaAcrossStoresAndTokens():void {
  $now=1800000000;for($i=0;$i<50;$i++)self::assertTrue(PrintifyAccountQuota::reserve($this->scope+['store_id'=>$i,'token'=>'never-persist'],'/v1/catalog/blueprints.json','GET','attempt-'.$i,$now));
  self::assertWPError(PrintifyAccountQuota::reserve($this->scope+['store_id'=>900],'/v1/catalog/blueprints.json','GET','overflow',$now));
 }
 public function testCooldownAndAttemptConsumption():void {
  self::assertTrue(PrintifyAccountQuota::reserve($this->scope,'/v1/shops.json','GET','one',1800000000));
  self::assertWPError(PrintifyAccountQuota::reserve($this->scope,'/v1/shops.json','GET','one',1800000000));
  self::assertTrue(PrintifyAccountQuota::observe($this->scope,429,['Retry-After'=>'120'],'one',1800000000));
  self::assertWPError(PrintifyAccountQuota::reserve($this->scope,'/v1/shops.json','GET','two',1800000061));
  self::assertTrue(PrintifyAccountQuota::reserve($this->scope,'/v1/shops.json','GET','three',1800000121));
 }
 public function testCircuitOpensAfterThreeTransportFailures():void {
  for($i=0;$i<3;$i++){self::assertTrue(PrintifyAccountQuota::reserve($this->scope,'/v1/shops.json','GET','failure-'.$i,1800000000));self::assertTrue(PrintifyAccountQuota::observe($this->scope,new WP_Error('synthetic','Do not store this message'),[],'failure-'.$i,1800000000));}
  self::assertWPError(PrintifyAccountQuota::reserve($this->scope,'/v1/shops.json','GET','blocked',1800000001));
  self::assertTrue(PrintifyAccountQuota::reserve($this->scope,'/v1/shops.json','GET','recovered',1800000061));
 }
 public function testCallerTransactionIsRejectedWithoutRollback():void {
  global $wpdb;$wpdb->query('START TRANSACTION');self::assertWPError(PrintifyAccountQuota::reserve($this->scope,'/v1/shops.json','GET','nested',1800000000));self::assertSame('1',(string)$wpdb->get_var('SELECT @@in_transaction'));$wpdb->query('ROLLBACK');
 }
 public function testMissingAccountFailsClosed():void {self::assertWPError(PrintifyAccountQuota::reserve([], '/v1/shops.json','GET','missing'));}
 public function testGlobalAndPublicationCeilingsAreSharedAndBounded():void {
  $now=1800000000;for($i=0;$i<300;$i++)self::assertTrue(PrintifyAccountQuota::reserve($this->scope+['shop_key'=>$i%2?'personalized_pod':'standard_pod'],'/v1/shops.json','GET','global-'.$i,$now));self::assertWPError(PrintifyAccountQuota::reserve($this->scope,'/v1/shops.json','GET','global-overflow',$now));
  $later=$now+60;for($i=0;$i<100;$i++)self::assertTrue(PrintifyAccountQuota::reserve($this->scope,'/v1/shops/12/products/p1/publish.json','POST','publication-'.$i,$later));self::assertWPError(PrintifyAccountQuota::reserve($this->scope,'/v1/shops/12/products/p1/publish.json','POST','publication-overflow',$later));
 }
 public function testAuthenticationFailureOpensAccountCooldown():void{self::assertTrue(PrintifyAccountQuota::reserve($this->scope,'/v1/shops.json','GET','auth',1800000000));self::assertTrue(PrintifyAccountQuota::observe($this->scope,401,[],'auth',1800000000));self::assertWPError(PrintifyAccountQuota::reserve($this->scope,'/v1/shops.json','GET','blocked-auth',1800000001));}
}
