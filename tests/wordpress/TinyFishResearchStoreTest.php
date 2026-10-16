<?php
declare(strict_types=1);
require_once __DIR__.'/Support/ReviewedLocalFixture.php';
use DigiForge\Research\TinyFishResearchContract;
use DigiForge\Research\TinyFishResearchStore;
final class TinyFishResearchStoreTest extends WP_UnitTestCase
{
 private array $scope;private TinyFishResearchStore $store;private int $now;private array $actors;
 protected function setUp():void{
  parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();$this->actors=ReviewedLocalFixture::actors('personalized_pod');$this->now=time();
  $this->scope=['shop'=>'personalized_pod','environment'=>'sandbox','integration_id'=>7,'provider_shop_id'=>'28930593','account_sha256'=>hash('sha256',wp_generate_uuid4()),'session_sha256'=>hash('sha256','synthetic-session'),'approval_sha256'=>hash('sha256','synthetic-approval'),'scope_revision_sha256'=>hash('sha256','synthetic-scope'),'expires_at'=>$this->now+900,'max_requests'=>10,'max_session_seconds'=>600,'max_cost_usd_micros'=>20000,'rate_usd_micros_per_minute'=>2000,'rate_evidence_sha256'=>hash('sha256','synthetic-rate'),'families'=>['PP-002'],'destinations'=>['US','CA','GB','EU','DE','AU']];
  $this->store=new TinyFishResearchStore(new TinyFishResearchContract(str_repeat('s',32)),str_repeat('p',32),fn()=>$this->now);
 }
 protected function tearDown():void{self::assertTrue(DigiForge\Core\Settings::get('stop_all'));self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::get('automation_armed'));parent::tearDown();}
 private function create():array{$s=$this->store->create($this->scope,wp_generate_uuid4(),20000,1);self::assertNotWPError($s);return $s;}
 public function testStoreExists():void{self::assertTrue(class_exists(TinyFishResearchStore::class));}
 public function testDurableBudgetAndReplaySurviveNewRepositoryInstance():void{
  $s=$this->create();self::assertSame(20000,$s['reserved_usd_micros']);self::assertFalse($s['live_execution_authorized']);$other=$this->scope;$other['session_sha256']=hash('sha256','another-session');self::assertWPError($this->store->create($other,wp_generate_uuid4(),20000,1));
  self::assertWPError($this->store->create($this->scope,wp_generate_uuid4(),20000,1));$new=new TinyFishResearchStore(new TinyFishResearchContract(str_repeat('s',32)),str_repeat('p',32),fn()=>$this->now);self::assertNotWPError($new->read($this->scope));
 }
 public function testLeaseRevisionAndUncertainDispatchNeverReplayOrRefund():void{
  $s=$this->create();$worker=hash('sha256','worker1');$claimed=$this->store->claim($this->scope,$s['revision'],wp_generate_uuid4(),$worker);self::assertNotWPError($claimed);
  self::assertWPError($this->store->claim($this->scope,$claimed['revision'],wp_generate_uuid4(),hash('sha256','worker2')));
  $this->now++;$step=$this->store->advance($this->scope,$claimed['revision'],wp_generate_uuid4(),$worker,'EXTRACT','https://printify.com/app/products/68/generic-brand/mug');self::assertNotWPError($step);self::assertSame('UNKNOWN_RECONCILIATION_REQUIRED',$step['status']);self::assertWPError($this->store->advance($this->scope,$claimed['revision'],wp_generate_uuid4(),$worker,'EXTRACT','https://printify.com/app/products/68/generic-brand/mug'));
  $this->now+=60;self::assertWPError($this->store->claim($this->scope,$step['revision'],wp_generate_uuid4(),hash('sha256','worker2')));self::assertSame(20000,$this->store->read($this->scope)['reserved_usd_micros']);
 }
 public function testEncryptedProfileRestoreExpiryRevocationAndNoAuditLeak():void{
  $s=$this->create();$stored=$this->store->saveProfile($this->scope,$s['revision'],wp_generate_uuid4(),'synthetic-opaque-handle');self::assertNotWPError($stored);self::assertSame('synthetic-opaque-handle',$this->store->restoreProfile($this->scope));
  global $wpdb;$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",TinyFishResearchStore::storageKey($this->scope)));self::assertStringNotContainsString('synthetic-opaque-handle',$raw);
  $audit=$wpdb->get_col("SELECT context FROM ".DigiForge\Database\Tables::audit_log()." WHERE object_type='tinyfish_research'");self::assertStringNotContainsString('synthetic-opaque-handle',implode('',$audit));
  self::assertNotWPError($this->store->revoke($this->scope,$stored['revision'],wp_generate_uuid4()));self::assertWPError($this->store->restoreProfile($this->scope));self::assertSame(20000,$this->store->read($this->scope)['reserved_usd_micros']);
 }
 public function testCrossShopScopeAndAuthorityRevocationDeny():void{
  $this->create();$other=$this->scope;$other['shop']='digital';self::assertWPError($this->store->read($other));$other=$this->scope;$other['provider_shop_id']='123';self::assertWPError($this->store->read($other));ShopAccessFixture::provision([]);self::assertWPError($this->store->read($this->scope));
 }
 public function testAuditFailureRollsBackCheckpointAndReservation():void{
  global $wpdb;$bad=static fn($sql)=>str_starts_with($sql,'INSERT INTO `'.DigiForge\Database\Tables::audit_log().'`')?'INSERT INTO missing_tinyfish_audit (x) VALUES (1)':$sql;$old=$wpdb->suppress_errors(true);add_filter('query',$bad);
  try{self::assertWPError($this->store->create($this->scope,wp_generate_uuid4(),20000,1));}finally{remove_filter('query',$bad);$wpdb->suppress_errors($old);}self::assertNotWPError($this->create());
 }
 public function testExpiredProfileAndBoundedPreflightRetriesFailClosed():void{
  $s=$this->create();$s=$this->store->saveProfile($this->scope,$s['revision'],wp_generate_uuid4(),'synthetic-handle');self::assertNotWPError($s);$worker=hash('sha256','retry-worker');$s=$this->store->claim($this->scope,$s['revision'],wp_generate_uuid4(),$worker);self::assertNotWPError($s);
  self::assertWPError($this->store->retryPreflight($this->scope,$s['revision'],wp_generate_uuid4(),$worker,'HTTP_402'));
  for($i=0;$i<3;$i++){$s=$this->store->retryPreflight($this->scope,$s['revision'],wp_generate_uuid4(),$worker,'LOCAL_INTERCEPTION_UNAVAILABLE');self::assertNotWPError($s);}self::assertSame('PAUSED',$s['status']);self::assertWPError($this->store->restoreProfile($this->scope));
  $this->now+=601;self::assertWPError($this->store->claim($this->scope,$s['revision'],wp_generate_uuid4(),$worker));self::assertSame(20000,$this->store->read($this->scope)['reserved_usd_micros']);
 }
 public function testOldSignedCheckpointSnapshotCannotReplaceCurrentAuditHistory():void{
  $s=$this->create();global $wpdb;$key=TinyFishResearchStore::storageKey($this->scope);$old=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key));
  self::assertNotWPError($this->store->claim($this->scope,$s['revision'],wp_generate_uuid4(),hash('sha256','snapshot-worker')));self::assertSame(1,$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s",$old,$key)));self::assertWPError($this->store->read($this->scope));
 }
 public function testSeparateConnectionContendingForLedgerCannotConsumeSameSlot():void{
  $s=$this->create();global $wpdb;$wpdb->query('COMMIT');$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$key=TinyFishResearchStore::storageKey($this->scope);$prior=$wpdb->get_var('SELECT @@session.innodb_lock_wait_timeout');$wpdb->query('SET SESSION innodb_lock_wait_timeout=1');
  try{$other->query('START TRANSACTION');$other->get_var($other->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",$key));$old=$wpdb->suppress_errors(true);try{self::assertWPError($this->store->claim($this->scope,$s['revision'],wp_generate_uuid4(),hash('sha256','contended-worker')));}finally{$wpdb->suppress_errors($old);}}
  finally{$other->query('ROLLBACK');$other->close();$wpdb->query('SET SESSION innodb_lock_wait_timeout='.(int)$prior);}
  self::assertNotWPError($this->store->claim($this->scope,$s['revision'],wp_generate_uuid4(),hash('sha256','after-lock-worker')));
 }

 public function testExpiredLeaseCanRecoverOnlyBeforeAnyIntentWasConsumed():void{
  $s=$this->create();$a=hash('sha256','lease-a');$b=hash('sha256','lease-b');$s=$this->store->claim($this->scope,$s['revision'],wp_generate_uuid4(),$a);self::assertNotWPError($s);$this->now+=31;$s=$this->store->claim($this->scope,$s['revision'],wp_generate_uuid4(),$b);self::assertNotWPError($s);
  self::assertWPError($this->store->advance($this->scope,$s['revision'],wp_generate_uuid4(),$a,'EXTRACT','https://printify.com/app/products/68/generic-brand/mug'));self::assertNotWPError($this->store->advance($this->scope,$s['revision'],wp_generate_uuid4(),$b,'EXTRACT','https://printify.com/app/products/68/generic-brand/mug'));
 }
 public function testProfileExpiryAndNestedRollbackDoNotCommitOuterTransaction():void{
  $s=$this->create();$s=$this->store->saveProfile($this->scope,$s['revision'],wp_generate_uuid4(),'synthetic-expiring-handle');self::assertNotWPError($s);$this->now+=600;self::assertWPError($this->store->restoreProfile($this->scope));global $wpdb;$wpdb->query('ROLLBACK');self::assertWPError($this->store->read($this->scope));$wpdb->query('START TRANSACTION');
 }

 public function testAccountReservationCannotBeBypassedByAnotherIntegrationOrSession():void{
  self::assertNotWPError($this->store->create($this->scope,wp_generate_uuid4(),20000,3));$other=$this->scope;$other['session_sha256']=hash('sha256','second-budget-session');$other['integration_id']=8;self::assertWPError($this->store->create($other,wp_generate_uuid4(),20000,3));self::assertSame(20000,$this->store->read($this->scope)['reserved_usd_micros']);
 }

 public function testWrongEncryptionKeyAndMissingAuditCannotRestoreProfile():void{
  $s=$this->create();self::assertNotWPError($this->store->saveProfile($this->scope,$s['revision'],wp_generate_uuid4(),'synthetic-private-profile'));
  $wrong=new TinyFishResearchStore(new TinyFishResearchContract(str_repeat('s',32)),str_repeat('x',32),fn()=>$this->now);self::assertWPError($wrong->restoreProfile($this->scope));self::assertSame('synthetic-private-profile',$this->store->restoreProfile($this->scope));
  global $wpdb;$stream=substr(TinyFishResearchStore::storageKey($this->scope),19);$wpdb->delete(DigiForge\Database\Tables::audit_log(),['object_type'=>'tinyfish_research','object_id'=>$stream]);self::assertWPError($this->store->restoreProfile($this->scope));
 }

}
