<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyWebhookConfigFixture.php';
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\POD\PrintifyWebhookInbox;

/** Real local SQL boundaries and signed synthetic ingress; no adapter/authority stand-in. */
final class PrintifyWebhookTransactionBoundaryTest extends WP_UnitTestCase
{
 private string $binding='e123456789abcdef0123456789abcdef';
 private string $path;
 private string $savedConfig;
 private array $keys=[];
 private array $markers=[];
 private int $http=0;
 private $denyHttp;
 protected function setUp():void {
  parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);
  DigiForge\Core\Activator::activate();Settings::protectProduction();
  $this->path=PrintifyWebhookConfigFixture::path();$this->savedConfig=(string)file_get_contents($this->path);
  file_put_contents($this->path,wp_json_encode([$this->binding=>['site'=>home_url(),'shop_key'=>'personalized_pod','integration_id'=>123,'provider_shop_id'=>'123','current_secret'=>str_repeat('a',32)]]));chmod($this->path,0600);
  global $wpdb;self::assertNotFalse($wpdb->query('COMMIT'));self::assertNotFalse($wpdb->query('SET autocommit=1'));
  $this->denyHttp=function(){++$this->http;return new WP_Error('offline_webhook_transaction_boundary');};add_filter('pre_http_request',$this->denyHttp);
 }
 protected function tearDown():void {
  remove_filter('pre_http_request',$this->denyHttp);global $wpdb;$wpdb->query('ROLLBACK');$wpdb->query('SET autocommit=1');
  foreach($this->markers as $marker)$wpdb->delete($wpdb->options,['option_name'=>$marker]);
  foreach($this->keys as $key){$wpdb->delete($wpdb->options,['option_name'=>'digiforge_pfy_hook_'.$key]);$wpdb->delete($wpdb->options,['option_name'=>'digiforge_pfy_hook_state_'.$key]);$wpdb->delete(Tables::audit_log(),['object_type'=>'printify_webhook','object_id'=>$key],['%s','%s']);}
  file_put_contents($this->path,$this->savedConfig);self::assertSame(0,$this->http);self::assertTrue(Settings::safety_locked());self::assertTrue(Settings::get('stop_all',true));self::assertFalse(Settings::get('automation_armed',false));
  $wpdb->query('START TRANSACTION');parent::tearDown();
 }
 public function testCallerTransactionOpenedDuringNamedLockCannotBeImplicitlyCommitted():void {
  global $wpdb;$database=$wpdb;$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($database->prefix);$other->query('SET SESSION innodb_lock_wait_timeout=2');
  $raw=(string)wp_json_encode(['id'=>wp_generate_uuid4(),'type'=>'order:shipment:created','created_at'=>gmdate('Y-m-d H:i:s+00:00'),'resource'=>['id'=>'synthetic-boundary-order','type'=>'order','data'=>['shop_id'=>'123']]]);
  $signature='sha256='.hash_hmac('sha256',$raw,str_repeat('a',32));$event=PrintifyWebhookInbox::authenticate($this->binding,$raw,$signature);self::assertNotWPError($event);$key=$event['dedupe_key'];$this->keys[]=$key;
  $marker='df_test_inbox_caller_'.str_replace('-','_',wp_generate_uuid4());$this->markers[]=$marker;$fired=false;$opened=null;$inserted=null;
  $interleave=static function(string $sql)use($database,$marker,&$fired,&$opened,&$inserted):string {
   if(!$fired&&str_starts_with($sql,'SELECT GET_LOCK(')&&str_contains($sql,'df_pfy_hook_')){$fired=true;$opened=$database->query('START TRANSACTION');$inserted=$database->insert($database->options,['option_name'=>$marker,'option_value'=>'caller-pending','autoload'=>'no']);}
   return $sql;
  };
  add_filter('query',$interleave);
  try {
   $result=PrintifyWebhookInbox::receive($this->binding,$raw,$signature);
   $observed=['active_after'=>(string)$database->get_var('SELECT @@session.in_transaction'),'primary_marker'=>$database->get_var($database->prepare('SELECT option_value FROM '.$database->options.' WHERE option_name=%s',$marker)),'independent_before_rollback'=>$other->get_var($other->prepare('SELECT option_value FROM '.$other->options.' WHERE option_name=%s',$marker))];
   self::assertNotFalse($database->query('ROLLBACK'));
   $observed['independent_after_rollback']=$other->get_var($other->prepare('SELECT option_value FROM '.$other->options.' WHERE option_name=%s',$marker));
   $observed['receipt_after_rollback']=$other->get_var($other->prepare('SELECT option_value FROM '.$other->options.' WHERE option_name=%s','digiforge_pfy_hook_'.$key));
  }finally{remove_filter('query',$interleave);$database->query('ROLLBACK');$other->close();}
  self::assertTrue($fired);self::assertNotFalse($opened);self::assertSame(1,$inserted);self::assertSame('caller-pending',$observed['primary_marker']);
  $diagnostic=$observed;unset($diagnostic['receipt_after_rollback']);$diagnostic['receipt_persisted']=$observed['receipt_after_rollback']!==null;$diagnostic['result_state']=is_wp_error($result)?$result->get_error_code():($result['state']??null);
  self::assertNull($observed['independent_after_rollback'],'Caller work must remain rollbackable: '.wp_json_encode($diagnostic));
  self::assertWPError($result);self::assertSame('digiforge_printify_webhook_transaction_active',$result->get_error_code());self::assertSame('1',$observed['active_after']);self::assertNull($observed['independent_before_rollback']);self::assertNull($observed['receipt_after_rollback']);
 }
 public function testPreexistingCallerTransactionAndPendingWriteRemainRollbackable():void {
  global $wpdb;$database=$wpdb;$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($database->prefix);$marker='df_test_inbox_outer_'.str_replace('-','_',wp_generate_uuid4());$this->markers[]=$marker;
  try {
   self::assertNotFalse($database->query('START TRANSACTION'));self::assertSame(1,$database->insert($database->options,['option_name'=>$marker,'option_value'=>'outer-pending','autoload'=>'no']));
   [$raw,$signature,$key]=$this->event();$result=PrintifyWebhookInbox::receive($this->binding,$raw,$signature);self::assertWPError($result);self::assertSame('digiforge_printify_webhook_transaction_active',$result->get_error_code());self::assertSame('1',(string)$database->get_var('SELECT @@session.in_transaction'));
   self::assertSame('outer-pending',$database->get_var($database->prepare('SELECT option_value FROM '.$database->options.' WHERE option_name=%s',$marker)));self::assertNull($other->get_var($other->prepare('SELECT option_value FROM '.$other->options.' WHERE option_name=%s',$marker)));
   self::assertNotFalse($database->query('ROLLBACK'));self::assertNull($other->get_var($other->prepare('SELECT option_value FROM '.$other->options.' WHERE option_name=%s',$marker)));self::assertNull($other->get_var($other->prepare('SELECT option_value FROM '.$other->options.' WHERE option_name=%s','digiforge_pfy_hook_'.$key)));
  }finally{$database->query('ROLLBACK');$other->close();}
 }
 public function testNormalReceiptAndDuplicateRetainOneDurableReceiptAndAudit():void {
  [$raw,$signature,$key]=$this->event();$received=PrintifyWebhookInbox::receive($this->binding,$raw,$signature);self::assertNotWPError($received);self::assertSame('RECEIVED_LOCAL',$received['state']);self::assertFalse($received['business_transition_authorized']);
  $duplicate=PrintifyWebhookInbox::receive($this->binding,$raw,$signature);self::assertNotWPError($duplicate);self::assertSame('DUPLICATE_NO_TRANSITION',$duplicate['state']);self::assertFalse($duplicate['business_transition_authorized']);
  $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);global $wpdb;$other->set_prefix($wpdb->prefix);
  try{self::assertSame('1',(string)$other->get_var($other->prepare('SELECT COUNT(*) FROM '.$other->options.' WHERE option_name=%s','digiforge_pfy_hook_'.$key)));self::assertSame('1',(string)$other->get_var($other->prepare('SELECT COUNT(*) FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s','printify_webhook_received','printify_webhook',$key)));}finally{$other->close();}
 }
 public function testDatabaseObjectSwapDuringNamedLockPreservesReplacementTransactionAndReleasesOriginalLock():void {
  global $wpdb;$database=$wpdb;$replacement=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$replacement->set_prefix($database->prefix);$replacement->query('SET SESSION innodb_lock_wait_timeout=2');
  [$raw,$signature,$key]=$this->event();$marker='df_test_inbox_swap_'.str_replace('-','_',wp_generate_uuid4());$this->markers[]=$marker;$fired=false;$opened=null;$inserted=null;
  $swap=static function(string $sql)use($replacement,$marker,&$fired,&$opened,&$inserted):string {
   if(!$fired&&str_starts_with($sql,'SELECT GET_LOCK(')&&str_contains($sql,'df_pfy_hook_')){$fired=true;$opened=$replacement->query('START TRANSACTION');$inserted=$replacement->insert($replacement->options,['option_name'=>$marker,'option_value'=>'replacement-pending','autoload'=>'no']);$GLOBALS['wpdb']=$replacement;}return $sql;
  };
  add_filter('query',$swap);
  try {
   $result=PrintifyWebhookInbox::receive($this->binding,$raw,$signature);
   $observed=['active'=>(string)$replacement->get_var('SELECT @@session.in_transaction'),'primary_marker'=>$replacement->get_var($replacement->prepare('SELECT option_value FROM '.$replacement->options.' WHERE option_name=%s',$marker)),'independent_before'=>$database->get_var($database->prepare('SELECT option_value FROM '.$database->options.' WHERE option_name=%s',$marker)),'lock_owner'=>$replacement->get_var($replacement->prepare('SELECT IS_USED_LOCK(%s)','df_pfy_hook_'.substr($key,0,48)))];
   self::assertNotFalse($replacement->query('ROLLBACK'));$observed['independent_after']=$database->get_var($database->prepare('SELECT option_value FROM '.$database->options.' WHERE option_name=%s',$marker));
  }finally{remove_filter('query',$swap);$GLOBALS['wpdb']=$database;$replacement->query('ROLLBACK');$replacement->close();$database->get_var($database->prepare('SELECT RELEASE_LOCK(%s)','df_pfy_hook_'.substr($key,0,48)));}
  self::assertTrue($fired);self::assertNotFalse($opened);self::assertSame(1,$inserted);self::assertNull($observed['independent_after'],'Replacement caller work must never be committed.');self::assertWPError($result);self::assertSame('digiforge_printify_webhook_transaction_active',$result->get_error_code());self::assertSame('1',$observed['active']);self::assertSame('replacement-pending',$observed['primary_marker']);self::assertNull($observed['independent_before']);self::assertNull($observed['lock_owner'],'The original connection must release its acquired named lock.');
 }
 private function event():array {
  $raw=(string)wp_json_encode(['id'=>wp_generate_uuid4(),'type'=>'order:shipment:created','created_at'=>gmdate('Y-m-d H:i:s+00:00'),'resource'=>['id'=>'synthetic-boundary-order','type'=>'order','data'=>['shop_id'=>'123']]]);$signature='sha256='.hash_hmac('sha256',$raw,str_repeat('a',32));$event=PrintifyWebhookInbox::authenticate($this->binding,$raw,$signature);self::assertNotWPError($event);$this->keys[]=$event['dedupe_key'];return [$raw,$signature,$event['dedupe_key']];
 }
 public function testDatabaseObjectSwapInsideCallbackCannotAcknowledgeUncommittedReplacementWrites():void {
  global $wpdb;$database=$wpdb;$replacement=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$replacement->set_prefix($database->prefix);$replacement->query('SET SESSION innodb_lock_wait_timeout=2');
  [$raw,$signature,$key]=$this->event();$marker='df_test_inbox_callback_'.str_replace('-','_',wp_generate_uuid4());$this->markers[]=$marker;$fired=false;$activeAtSwap=null;
  self::assertNotFalse($replacement->query('START TRANSACTION'));self::assertSame(1,$replacement->insert($replacement->options,['option_name'=>$marker,'option_value'=>'callback-pending','autoload'=>'no']));
  $swap=static function(string $sql)use($database,$replacement,$key,&$fired,&$activeAtSwap):string {
   if(!$fired&&str_starts_with($sql,'SELECT option_value FROM ')&&str_contains($sql,'digiforge_pfy_hook_'.$key)){$fired=true;$activeAtSwap=(string)$database->get_var('SELECT @@session.in_transaction');$GLOBALS['wpdb']=$replacement;}return $sql;
  };
  add_filter('query',$swap);
  try {
   $result=PrintifyWebhookInbox::receive($this->binding,$raw,$signature);
   $observed=['original_active'=>(string)$database->get_var('SELECT @@session.in_transaction'),'replacement_active'=>(string)$replacement->get_var('SELECT @@session.in_transaction'),'replacement_marker'=>$replacement->get_var($replacement->prepare('SELECT option_value FROM '.$replacement->options.' WHERE option_name=%s',$marker)),'independent_marker'=>$database->get_var($database->prepare('SELECT option_value FROM '.$database->options.' WHERE option_name=%s',$marker)),'replacement_receipt'=>$replacement->get_var($replacement->prepare('SELECT option_value FROM '.$replacement->options.' WHERE option_name=%s','digiforge_pfy_hook_'.$key))!==null,'independent_receipt'=>$database->get_var($database->prepare('SELECT option_value FROM '.$database->options.' WHERE option_name=%s','digiforge_pfy_hook_'.$key))!==null];
   self::assertNotFalse($replacement->query('ROLLBACK'));$observed['marker_after_explicit_rollback']=$database->get_var($database->prepare('SELECT option_value FROM '.$database->options.' WHERE option_name=%s',$marker));
  }finally{remove_filter('query',$swap);$GLOBALS['wpdb']=$database;$database->query('ROLLBACK');$replacement->query('ROLLBACK');$replacement->close();}
  self::assertTrue($fired);self::assertSame('1',$activeAtSwap);self::assertWPError($result,'A receipt on the replacement transaction is not durable: '.wp_json_encode($observed));self::assertSame('digiforge_printify_webhook_transaction_changed',$result->get_error_code());self::assertSame('0',$observed['original_active']);self::assertSame('1',$observed['replacement_active']);self::assertSame('callback-pending',$observed['replacement_marker']);self::assertNull($observed['independent_marker']);self::assertFalse($observed['independent_receipt']);self::assertNull($observed['marker_after_explicit_rollback']);
 }
 public function testUnconfirmedCommitCannotAcknowledgeReceiptAndLeavesIndependentCallerTransactionUntouched():void {
  global $wpdb;$database=$wpdb;$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($database->prefix);[$raw,$signature,$key]=$this->event();$marker='df_test_inbox_commit_'.str_replace('-','_',wp_generate_uuid4());$this->markers[]=$marker;$fired=false;
  self::assertNotFalse($other->query('START TRANSACTION'));self::assertSame(1,$other->insert($other->options,['option_name'=>$marker,'option_value'=>'independent-pending','autoload'=>'no']));
  $omit=static function(string $sql)use(&$fired):string{if(!$fired&&$sql==='COMMIT'){$fired=true;return 'SELECT 1 /* synthetic missing COMMIT */';}return $sql;};add_filter('query',$omit);
  try {
   $result=PrintifyWebhookInbox::receive($this->binding,$raw,$signature);
   $observed=['active'=>(string)$database->get_var('SELECT @@session.in_transaction'),'independent_receipt'=>$other->get_var($other->prepare('SELECT option_value FROM '.$other->options.' WHERE option_name=%s','digiforge_pfy_hook_'.$key)),'caller_active'=>(string)$other->get_var('SELECT @@session.in_transaction'),'caller_marker'=>$other->get_var($other->prepare('SELECT option_value FROM '.$other->options.' WHERE option_name=%s',$marker))];
   self::assertNotFalse($database->query('ROLLBACK'));self::assertNotFalse($other->query('ROLLBACK'));$observed['marker_after_explicit_rollback']=$database->get_var($database->prepare('SELECT option_value FROM '.$database->options.' WHERE option_name=%s',$marker));
  }finally{remove_filter('query',$omit);$database->query('ROLLBACK');$other->query('ROLLBACK');$other->close();}
  self::assertTrue($fired);self::assertWPError($result,'An unterminated original frame cannot acknowledge a receipt: '.wp_json_encode($observed));self::assertSame('digiforge_printify_webhook_commit_unknown',$result->get_error_code());self::assertSame('0',$observed['active']);self::assertNull($observed['independent_receipt']);self::assertSame('1',$observed['caller_active']);self::assertSame('independent-pending',$observed['caller_marker']);self::assertNull($observed['marker_after_explicit_rollback']);
 }
 public function testDurableCommitWithLostAcknowledgementReplaysWithoutAnotherReceiptOrAudit():void {
  global $wpdb;$database=$wpdb;[$raw,$signature,$key]=$this->event();$fired=false;$omit=static function(string $sql)use($database,&$fired):string{if(!$fired&&$sql==='COMMIT'){$fired=true;$database->query('COMMIT');return 'SELECT FROM /* synthetic lost COMMIT acknowledgement */';}return $sql;};
  $prior=$database->suppress_errors(true);add_filter('query',$omit);try{$result=PrintifyWebhookInbox::receive($this->binding,$raw,$signature);}finally{remove_filter('query',$omit);$database->suppress_errors($prior);}
  self::assertTrue($fired);self::assertWPError($result);self::assertSame('digiforge_printify_webhook_commit_unknown',$result->get_error_code());$replay=PrintifyWebhookInbox::receive($this->binding,$raw,$signature);self::assertNotWPError($replay);self::assertSame('DUPLICATE_NO_TRANSITION',$replay['state']);
  self::assertSame('1',(string)$database->get_var($database->prepare('SELECT COUNT(*) FROM '.$database->options.' WHERE option_name=%s','digiforge_pfy_hook_'.$key)));self::assertSame('1',(string)$database->get_var($database->prepare('SELECT COUNT(*) FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s','printify_webhook_received','printify_webhook',$key)));
 }
 public function testModuleRevokedDuringLockReleaseDeniesResponseWithoutUndoingCommittedDecision():void {
  PortalAccessFixture::reset();PortalAccessFixture::enroll();global $wpdb;$database=$wpdb;$database->query('COMMIT');$database->query('SET autocommit=1');[$raw,$signature,$key]=$this->event();self::assertNotWPError(PrintifyWebhookInbox::receive($this->binding,$raw,$signature));$user=wp_get_current_user();$cap='manage_digiforge_connections';$had=array_key_exists($cap,$user->caps);$old=$user->caps[$cap]??null;$fired=false;
  $revoke=static function(string $sql)use($user,$cap,&$fired):string{if(!$fired&&str_starts_with($sql,'SELECT RELEASE_LOCK(')&&str_contains($sql,'df_pfy_hook_')){$fired=true;$user->add_cap($cap,false);}return $sql;};
  add_filter('query',$revoke);
  try{$result=PrintifyWebhookInbox::reconcile($key,'RETRY_LOCAL',0,'synthetic final response revocation');}finally{remove_filter('query',$revoke);if($had)$user->add_cap($cap,$old);else $user->remove_cap($cap);}
  try{self::assertTrue($fired);$state=json_decode((string)$database->get_var($database->prepare('SELECT option_value FROM '.$database->options.' WHERE option_name=%s','digiforge_pfy_hook_state_'.$key)),true);self::assertSame(1,$state['revision']);self::assertSame(1,$state['attempts']);self::assertSame('RETRY_LOCAL',$state['state']);self::assertSame('1',(string)$database->get_var($database->prepare('SELECT COUNT(*) FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s','printify_webhook_reconciled','printify_webhook',$key)));self::assertSame('0',(string)$database->get_var('SELECT @@session.in_transaction'));self::assertWPError($result,'A committed private decision must not be returned after current module authority is lost.');self::assertSame(403,$result->get_error_data()['status']);}finally{PortalAccessFixture::reset();}
 }
}
