<?php
declare(strict_types=1);
use DigiForge\Connector\Printify\{NativeHeldJournal as Journal,NativeHeldJournalSchema as Schema,NativePortContract};
require_once __DIR__.'/../support/NativePrintifyContractFixture.php';
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
require_once __DIR__.'/fixtures/PrintifyResearchAccessFixture.php';
/** Only this explicit disposable fixture installs proposed tables. No production migration exists. */
final class NativeHeldJournalWordPressTest extends WP_UnitTestCase {
 private int $actor;private array $binding;private $http;
 protected function setUp():void {
  parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);PortalAccessFixture::reset();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
  global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
  foreach(Schema::tables()as $table)$wpdb->query('DROP TABLE IF EXISTS '.$table);foreach(Schema::statements()as $sql)self::assertNotFalse($wpdb->query($sql));
  $this->actor=self::factory()->user->create(['role'=>'administrator']);PrintifyResearchAccessFixture::assign($this->actor);PortalAccessFixture::login($this->actor);$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
  $this->binding=NativePrintifyContractFixture::binding();$this->binding['operation_id']=hash('sha256',wp_generate_uuid4());$this->binding['actor_id']=$this->actor;
  $this->http=static function(){throw new RuntimeException('Provider HTTP prohibited');};add_filter('pre_http_request',$this->http,PHP_INT_MAX);
 }
 protected function tearDown():void {
  remove_filter('pre_http_request',$this->http,PHP_INT_MAX);self::assertTrue(DigiForge\Core\Settings::safety_locked());global $wpdb;$wpdb->query('ROLLBACK');$wpdb->query('SET autocommit=1');PortalAccessFixture::reset();$wpdb->query('START TRANSACTION');parent::tearDown();
 }
 private function prepare():array {$r=(new Journal())->prepare($this->binding,hash('sha256','candidate-'.$this->binding['operation_id']));self::assertNotWPError($r);return $r;}
 private function transition(string $action,int $revision=1):array|WP_Error{return (new Journal())->transition($this->binding['operation_id'],'personalized_pod',$this->actor,$revision,$action);}
 public function testDurableRestartLocalFenceAndUnknownNeverRearm():void {
  $r=$this->prepare();self::assertFalse($r['record']['approval_verified']);self::assertFalse($r['record']['dispatch_permitted']);
  $f=$this->transition('FENCE');self::assertNotWPError($f);self::assertSame('FENCED',$f['record']['state']);self::assertSame($f,(new Journal())->read($this->binding['operation_id'],'personalized_pod',$this->actor));
  self::assertWPError($this->transition('FENCE',2));self::assertWPError($this->transition('CANCEL',2));$u=$this->transition('UNKNOWN',2);self::assertNotWPError($u);self::assertSame('UNKNOWN',$u['record']['state']);self::assertWPError($this->transition('FENCE',3));self::assertWPError($this->transition('CANCEL',3));self::assertFalse($u['record']['independent_checkpoint_verified']);
 }
 public function testCancellationNeverReleasesCandidateKeyOrAcceptsChangedBinding():void {
  $r=$this->prepare();self::assertNotWPError($this->transition('CANCEL'));$key=$r['record']['candidate_key'];$changed=$this->binding;$changed['operation_id']=hash('sha256','new-'.$changed['operation_id']);self::assertWPError((new Journal())->prepare($changed,$key));
  $changed=$this->binding;$changed['approval_reference']=str_repeat('9',64);self::assertWPError((new Journal())->prepare($changed,hash('sha256','another-candidate')));self::assertWPError($this->transition('FENCE',2));
 }
 public function testStaleRevisionCrossShopAndRevokedRoleDenyWithoutStateChange():void {
  $r=$this->prepare();self::assertWPError($this->transition('FENCE',2));self::assertWPError((new Journal())->read($this->binding['operation_id'],'standard_pod',$this->actor));
  wp_get_current_user()->add_cap('manage_digiforge_pod',false);try{self::assertWPError($this->transition('FENCE'));}finally{wp_get_current_user()->add_cap('manage_digiforge_pod',true);}self::assertSame($r,(new Journal())->read($this->binding['operation_id'],'personalized_pod',$this->actor));
 }
 public function testDigitalScopeCannotReachAdmissionStorageAuditJobsOrLegacyIntents():void {
  global $wpdb;$tables=[DigiForge\Database\Tables::audit_log(),DigiForge\Database\Tables::jobs(),DigiForge\Database\Tables::pod_provider_intents()];$before=[];foreach($tables as $t)$before[$t]=(string)$wpdb->get_var('SELECT COUNT(*) FROM '.$t);
  $b=$this->binding;$b['shop_id']=1;$b['shop_key']='digital';self::assertWPError((new Journal())->prepare($b,str_repeat('d',64)));
  foreach($before as $t=>$count)self::assertSame($count,(string)$wpdb->get_var('SELECT COUNT(*) FROM '.$t));foreach(Schema::tables()as $t)self::assertSame('0',(string)$wpdb->get_var('SELECT COUNT(*) FROM '.$t));
 }
 public function testOuterTransactionIsNotCommittedAndMissingSchemaDenies():void {
  global $wpdb;$wpdb->query('START TRANSACTION');self::assertWPError((new Journal())->prepare($this->binding,str_repeat('d',64)));self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'));$wpdb->query('ROLLBACK');[$j]=Schema::tables();$wpdb->query('DROP TABLE '.$j);self::assertWPError((new Journal())->prepare($this->binding,str_repeat('d',64)));
 }
 public function testTemporaryShadowTablesCannotClaimDurability():void {
  global $wpdb;[$j]=Schema::tables();$definition=$wpdb->get_row('SHOW CREATE TABLE '.$j,ARRAY_A);self::assertNotFalse($wpdb->query(preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$definition['Create Table'])));try{self::assertWPError((new Journal())->prepare($this->binding,str_repeat('d',64)));}finally{$wpdb->query('DROP TEMPORARY TABLE '.$j);}
 }
 public function testAuditFailureRollsBackBothJournalAndEvent():void {
  global $wpdb;$audit=DigiForge\Database\Tables::audit_log();$inject=static function(string $sql)use($audit):string{return str_starts_with($sql,'INSERT INTO `'.$audit.'`')&&str_contains($sql,'native_printify_held_transition')?'INSERT INTO missing_native_held_audit (id) VALUES (1)':$sql;};$prior=$wpdb->suppress_errors(true);add_filter('query',$inject);
  try{self::assertWPError((new Journal())->prepare($this->binding,str_repeat('d',64)));}finally{remove_filter('query',$inject);$wpdb->suppress_errors($prior);}
  foreach(Schema::tables()as $table)self::assertSame('0',(string)$wpdb->get_var('SELECT COUNT(*) FROM '.$table));self::assertNotWPError((new Journal())->prepare($this->binding,str_repeat('d',64)));
 }
 public function testEventAndAuditTamperingNeverBecomeEmptyOrSuccessfulReads():void {
  $r=$this->prepare();global $wpdb;[$j,$e]=Schema::tables();$wpdb->update($e,['payload'=>'{}'],['operation_id'=>$this->binding['operation_id']]);self::assertWPError((new Journal())->read($this->binding['operation_id'],'personalized_pod',$this->actor));
 }
 public function testMissingAnchorAndChangedHeadDeny():void {
  $r=$this->prepare();global $wpdb;$wpdb->delete(DigiForge\Database\Tables::audit_log(),['object_type'=>'native_printify_held','object_id'=>$r['event_hash']],['%s','%s']);self::assertWPError((new Journal())->read($this->binding['operation_id'],'personalized_pod',$this->actor));
 }
 public function testChangedHeadAndStorageFailureDeny():void {
  $r=$this->prepare();global $wpdb;[$j]=Schema::tables();$wpdb->update($j,['head_hash'=>str_repeat('0',64)],['operation_id'=>$this->binding['operation_id']]);self::assertWPError((new Journal())->read($this->binding['operation_id'],'personalized_pod',$this->actor));
  $guard=static function(string $sql):string {return str_contains($sql,'@@innodb_flush_log_at_trx_commit')?"SELECT 1 AS autocommit,0 AS active,0 AS flush_commit,0 AS binlog,0 AS sync_binlog,'REPEATABLE-READ' AS isolation_level":$sql;};add_filter('query',$guard);try{self::assertWPError($this->transition('FENCE'));}finally{remove_filter('query',$guard);}
 }
 public function testStorageExceptionsDenyWithoutAcknowledgement():void {
  $filter=static function(string $sql):string{if(str_contains($sql,'@@innodb_flush_log_at_trx_commit'))throw new RuntimeException('Synthetic storage unavailable');return $sql;};add_filter('query',$filter);try{self::assertWPError((new Journal())->prepare($this->binding,str_repeat('d',64)));}finally{remove_filter('query',$filter);}
 }
 public function testInterruptedEventInsertRollsBackWithoutClaimingSuccess():void {
  global $wpdb;[, $events]=Schema::tables();$filter=static function(string $sql)use($events):string{return str_starts_with($sql,'INSERT INTO `'.$events.'`')?'INSERT INTO missing_native_event_store (id) VALUES (1)':$sql;};$prior=$wpdb->suppress_errors(true);add_filter('query',$filter);
  try{self::assertWPError((new Journal())->prepare($this->binding,str_repeat('d',64)));}finally{remove_filter('query',$filter);$wpdb->suppress_errors($prior);}foreach(Schema::tables()as $table)self::assertSame('0',(string)$wpdb->get_var('SELECT COUNT(*) FROM '.$table));self::assertNotWPError((new Journal())->prepare($this->binding,str_repeat('d',64)));
 }
 public function testIdenticalCandidateKeysAndOperationIdsAreIsolatedAcrossShops():void {
  self::assertNotWPError((new Journal())->prepare($this->binding,str_repeat('d',64)));$other=self::factory()->user->create(['role'=>'administrator']);PrintifyResearchAccessFixture::assign($other,'standard_pod');PortalAccessFixture::login($other);global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');$binding=$this->binding;$binding['shop_id']=3;$binding['shop_key']='standard_pod';$binding['actor_id']=$other;
  self::assertNotWPError((new Journal())->prepare($binding,str_repeat('d',64)));[$journal]=Schema::tables();self::assertSame('2',(string)$wpdb->get_var('SELECT COUNT(*) FROM '.$journal));self::assertWPError((new Journal())->read($this->binding['operation_id'],'personalized_pod',$other));
 }
 public function testLostCommitAcknowledgementConsumesLocalSlotWithoutRetry():void {
  global $wpdb;$lost=false;$inject=function(string $sql)use(&$lost,$wpdb):string{if($sql==='COMMIT'&&!$lost){$lost=true;$wpdb->query('COMMIT');return 'SELECT * FROM missing_native_commit_ack';}return $sql;};$prior=$wpdb->suppress_errors(true);add_filter('query',$inject);
  try{$r=(new Journal())->prepare($this->binding,str_repeat('d',64));self::assertWPError($r);self::assertSame('printify_native_held_journal_commit_unknown',$r->get_error_code());}finally{remove_filter('query',$inject);$wpdb->suppress_errors($prior);}
  self::assertTrue($lost);self::assertNotWPError((new Journal())->read($this->binding['operation_id'],'personalized_pod',$this->actor));self::assertWPError((new Journal())->prepare($this->binding,str_repeat('d',64)));
 }
 public function testJournalOnlyRollbackCannotHideRetainedAuditHistory():void {
  $this->prepare();global $wpdb;$saved=[];foreach(Schema::tables()as $t)$saved[$t]=$wpdb->get_results('SELECT * FROM '.$t,ARRAY_A);self::assertNotWPError($this->transition('FENCE'));
  foreach($saved as $t=>$rows){$wpdb->query('DELETE FROM '.$t);foreach($rows as $row)$wpdb->insert($t,$row);}self::assertWPError((new Journal())->read($this->binding['operation_id'],'personalized_pod',$this->actor));
 }
 public function testAllRootRollbackCounterexampleCannotQualifyIndependentRecovery():void {
  $r=$this->prepare();global $wpdb;$saved=[];foreach(Schema::tables()as $table)$saved[$table]=$wpdb->get_results('SELECT * FROM '.$table,ARRAY_A);$audit=DigiForge\Database\Tables::audit_log();$anchors=$wpdb->get_results("SELECT * FROM $audit WHERE object_type='native_printify_held'",ARRAY_A);
  $first= $this->transition('FENCE');self::assertNotWPError($first);foreach($saved as $table=>$rows){$wpdb->query('DELETE FROM '.$table);foreach($rows as $row)$wpdb->insert($table,$row);}$wpdb->delete($audit,['object_type'=>'native_printify_held']);foreach($anchors as $row)$wpdb->insert($audit,$row,array_fill(0,count($row),'%s'));
  $restored=(new Journal())->read($this->binding['operation_id'],'personalized_pod',$this->actor);self::assertNotWPError($restored);self::assertSame('HELD',$restored['record']['state']);self::assertFalse($restored['record']['independent_checkpoint_verified']);self::assertFalse($restored['record']['dispatch_permitted']);$again=$this->transition('FENCE');self::assertNotWPError($again);self::assertNotSame($first['record']['fence_hash'],$again['record']['fence_hash']);self::assertFalse($again['record']['dispatch_permitted']);
 }
 /** @dataProvider crashModes */
 public function testAbruptWorkerExitKeepsConfirmedRollbackDistinctFromLostAck(string $mode,int $exit):void {
  $this->prepare();$dir=sys_get_temp_dir().'/df-native-crash-'.wp_generate_uuid4();mkdir($dir,0700);$fixture=['synthetic_only'=>true,'actor'=>$this->actor,'cookie'=>$_COOKIE[LOGGED_IN_COOKIE],'operation'=>$this->binding['operation_id'],'trust_key'=>DIGIFORGE_SHOP_ACCESS_TRUST_KEY,'authority_file'=>DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE,'config'=>defined('WP_TESTS_CONFIG_FILE_PATH')?WP_TESTS_CONFIG_FILE_PATH:null,'crash_mode'=>$mode];$path=$dir.'/fixture.json';file_put_contents($path,wp_json_encode($fixture));chmod($path,0600);file_put_contents($dir.'/go','1');
  try{$worker=proc_open([PHP_BINARY,__DIR__.'/fixtures/native-held-worker.php',$path,$dir.'/result',$dir.'/go'],[0=>['file','/dev/null','r'],1=>['file',$dir.'/log','w'],2=>['file',$dir.'/err','w']],$pipes);self::assertIsResource($worker);self::assertSame($exit,proc_close($worker),file_get_contents($dir.'/err'));
   $r=(new Journal())->read($this->binding['operation_id'],'personalized_pod',$this->actor);self::assertNotWPError($r);self::assertSame($mode==='before_header'?'HELD':'FENCED',$r['record']['state']);self::assertFalse($r['record']['dispatch_permitted']);
   $next=$this->transition('FENCE');if($mode==='before_header')self::assertNotWPError($next);else self::assertWPError($next);
  }finally{foreach(glob($dir.'/*')as $p)unlink($p);rmdir($dir);}
 }
 public static function crashModes():array {return [['before_header',73],['after_commit',74]];}
 public function testTwoWorkersHaveOnePermanentLocalFenceWinner():void {
  $this->prepare();$dir=sys_get_temp_dir().'/df-native-workers-'.wp_generate_uuid4();mkdir($dir,0700);$fixture=['synthetic_only'=>true,'actor'=>$this->actor,'cookie'=>$_COOKIE[LOGGED_IN_COOKIE],'operation'=>$this->binding['operation_id'],'trust_key'=>DIGIFORGE_SHOP_ACCESS_TRUST_KEY,'authority_file'=>DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE,'config'=>defined('WP_TESTS_CONFIG_FILE_PATH')?WP_TESTS_CONFIG_FILE_PATH:null];$path=$dir.'/fixture.json';file_put_contents($path,wp_json_encode($fixture));chmod($path,0600);$workers=[];
  try{for($i=0;$i<2;$i++){$workers[$i]=proc_open([PHP_BINARY,__DIR__.'/fixtures/native-held-worker.php',$path,$dir.'/result'.$i,$dir.'/go'],[0=>['file','/dev/null','r'],1=>['file',$dir.'/log'.$i,'w'],2=>['file',$dir.'/err'.$i,'w']],$pipes);self::assertIsResource($workers[$i]);}
   $until=microtime(true)+30;while((!is_file($dir.'/result0.ready')||!is_file($dir.'/result1.ready'))&&microtime(true)<$until)usleep(10000);self::assertFileExists($dir.'/result0.ready');self::assertFileExists($dir.'/result1.ready');file_put_contents($dir.'/go','1');foreach($workers as $i=>$worker){self::assertSame(0,proc_close($worker),(string)file_get_contents($dir.'/err'.$i));unset($workers[$i]);}
   self::assertSame(1,(int)file_get_contents($dir.'/result0')+(int)file_get_contents($dir.'/result1'),file_get_contents($dir.'/result0.detail').' / '.file_get_contents($dir.'/result1.detail'));$r=(new Journal())->read($this->binding['operation_id'],'personalized_pod',$this->actor);self::assertNotWPError($r);self::assertSame('FENCED',$r['record']['state']);self::assertSame(2,$r['record']['revision']);
  }finally{foreach($workers as $worker){proc_terminate($worker);proc_close($worker);}foreach(glob($dir.'/*')as $p)unlink($p);rmdir($dir);}
 }
}
