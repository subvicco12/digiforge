<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/../support/NativePrintifyContractFixture.php';
use DigiForge\Connector\Printify\{NativeOrderSourceJournal as Journal,NativeOrderSourceSchema as Schema,ShopBinding,CredentialReference};
use DigiForge\POD\{ScopedProviderConnection,PrintifyApiReadWorkflow,PrintifyControlledTransport};
final class NativeOrderSourceJournalTest extends WP_UnitTestCase {
 private PrintifyApiApprovalFixture $fixture;
 protected function setUp():void{parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();}
 protected function tearDown():void{global $wpdb;try{if(class_exists(Schema::class))$wpdb->query('DROP TABLE IF EXISTS '.Schema::table());$this->fixture->restore();}finally{parent::tearDown();}}
 private function initialize():void{self::assertTrue(class_exists(Journal::class),'Transactional protected observation registry must exist.');global $wpdb;self::assertNotFalse($wpdb->query(Schema::statement()));}
 private function scope():ShopBinding{$v=PrintifyApiApprovalFixture::checked((new ScopedProviderConnection())->verified('personalized_pod',$this->fixture->integration,'printify.connection.read'));return new ShopBinding(2,'personalized_pod',$this->fixture->integration,'12','test',$v['review_revision'],new CredentialReference($this->fixture->integration),$v['binding_hash'],$v['expires_at']);}
 private function source(int $quantity=1):array{
  foreach(['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false,'printify_activation_authorized'=>true,'printify'=>true]as $k=>$v)$this->fixture->setting($k,$v);
  $w=new PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'ORDER_READ',['order_id'=>'journal_order']));$a=$this->fixture->approve($w,$p,'printify-api-read');$body=['id'=>'journal_order','status'=>'pending','line_items'=>[['id'=>'line1','product_id'=>'product1','variant_id'=>9,'quantity'=>$quantity]],'shipments'=>[]];$r=PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response($body)))->executeApiRead($p['hash'],$a['hash']));self::assertSame('SUCCEEDED',$r['record']['status']);DigiForge\Core\Settings::protectProduction();return $r;
 }
 public function testDurableObservationRevisionAndExactReplayStayHeld():void{
  $this->initialize();$r=$this->source();$s=$this->scope();$j=new Journal();$first=$j->register($s,1,$r['hash'],0,null);self::assertNotWPError($first);self::assertSame(1,$first['record']['observation_revision']);self::assertFalse($first['record']['external_execution_authorized']);self::assertFalse($first['record']['native_business_version_verified']);self::assertSame($first,$j->register($s,1,$r['hash'],0,null));self::assertSame($first,(new Journal())->read($s,1,$first['record']['order_identity']));
  $r2=$this->source(2);self::assertWPError($j->register($s,1,$r2['hash'],0,null));self::assertWPError($j->register($s,1,$r2['hash'],1,str_repeat('f',64)));$next=$j->register($s,1,$r2['hash'],1,$first['event_hash']);self::assertNotWPError($next);self::assertSame(2,$next['record']['observation_revision']);self::assertWPError($j->register($s,1,$r['hash'],1,$first['event_hash']));global $wpdb;self::assertSame('2',(string)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table()));
 }
 public function testLostCommitAcknowledgementReconcilesOnlyExactCurrentSource():void{
  $this->initialize();$r=$this->source();$s=$this->scope();global $wpdb;$lost=false;$inject=static function(string $q)use(&$lost,$wpdb):string{if($q==='COMMIT'&&!$lost){$lost=true;$wpdb->query('COMMIT');return 'SELECT * FROM missing_order_source_ack';}return $q;};$old=$wpdb->suppress_errors(true);add_filter('query',$inject);
  try{self::assertWPError((new Journal())->register($s,1,$r['hash'],0,null));}finally{remove_filter('query',$inject);$wpdb->suppress_errors($old);}self::assertTrue($lost);$v=(new Journal())->register($s,1,$r['hash'],0,null);self::assertNotWPError($v);self::assertSame(1,$v['record']['observation_revision']);self::assertSame('1',(string)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table()));
 }
 public function testIndependentSqlSessionCannotShareReservationOwnership():void{
  $this->initialize();$r=$this->source();$s=$this->scope();$source=PrintifyApiApprovalFixture::checked(DigiForge\Connector\Printify\NativeOrderSourceEvidence::resolve($s,1,$r['hash']));$scopeKey=DigiForge\Connector\Printify\NativePortContract::hash([$s->shopKey,$s->integrationId,$s->providerShopId,$s->environment]);$lock='dfnos:'.substr(DigiForge\Connector\Printify\NativePortContract::hash([$scopeKey,$source['order_identity']]),0,57);
  $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);self::assertSame('1',(string)$other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$lock)));
  try{self::assertWPError((new Journal())->register($s,1,$r['hash'],0,null));global $wpdb;self::assertSame('0',(string)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table()));}finally{self::assertSame('1',(string)$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$lock)));$other->close();}
  self::assertNotWPError((new Journal())->register($s,1,$r['hash'],0,null));
 }
 public static function corruption():array{return [['audit_failure'],['event_insert_failure'],['temporary_shadow'],['outer_transaction'],['missing_table'],['history_tamper'],['missing_anchor'],['revoked_role']];}
 /** @dataProvider corruption */
 public function testStorageAndAuthorizationFailuresNeverReturnAnAcknowledgement(string $mode):void{
  $this->initialize();$r=$this->source();$s=$this->scope();$j=new Journal();global $wpdb;$table=Schema::table();$first=null;$filter=null;$suppress=$wpdb->suppress_errors(true);
  try{
   if(in_array($mode,['history_tamper','missing_anchor','revoked_role'],true))$first=PrintifyApiApprovalFixture::checked($j->register($s,1,$r['hash'],0,null));
   switch($mode){
    case 'audit_failure':$filter=static fn(string $q):string=>str_starts_with($q,'INSERT INTO `'.DigiForge\Database\Tables::audit_log().'`')?'INSERT INTO missing_source_audit(id) VALUES(1)':$q;add_filter('query',$filter);break;
    case 'event_insert_failure':$filter=static fn(string $q):string=>str_starts_with($q,'INSERT INTO `'.$table.'`')?'INSERT INTO missing_source_journal(id) VALUES(1)':$q;add_filter('query',$filter);break;
    case 'temporary_shadow':$ddl=$wpdb->get_row('SHOW CREATE TABLE '.$table,ARRAY_A);self::assertNotFalse($wpdb->query(preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl['Create Table'])));break;
    case 'outer_transaction':$wpdb->query('START TRANSACTION');break;
    case 'missing_table':$wpdb->query('DROP TABLE '.$table);break;
    case 'history_tamper':$wpdb->query("UPDATE $table SET payload='{}'");break;
    case 'missing_anchor':$wpdb->delete(DigiForge\Database\Tables::audit_log(),['object_type'=>'native_order_source']);break;
    case 'revoked_role':wp_get_current_user()->add_cap('manage_digiforge_orders',false);break;
   }
   self::assertWPError($first===null?$j->register($s,1,$r['hash'],0,null):$j->read($s,1,$first['record']['order_identity']));
  }finally{if($filter!==null)remove_filter('query',$filter);if($mode==='temporary_shadow')$wpdb->query('DROP TEMPORARY TABLE '.$table);if($mode==='outer_transaction')$wpdb->query('ROLLBACK');$wpdb->suppress_errors($suppress);}
  if(in_array($mode,['audit_failure','event_insert_failure','temporary_shadow','outer_transaction'],true))self::assertSame('0',(string)$wpdb->get_var('SELECT COUNT(*) FROM '.$table));self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public function testWorkflowSnapshotUsesOnlyVerifiedLocalSourcesAndDeniesMissingStorage():void{
  $this->initialize();self::assertTrue(method_exists(Journal::class,'snapshot'),'Scoped consistent source inventory must exist.');$r=$this->source();$s=$this->scope();$j=new Journal();$v=PrintifyApiApprovalFixture::checked($j->register($s,1,$r['hash'],0,null));$port=new DigiForge\Connector\Printify\NativeCorePort();$out=$port->inspectWorkflows($s,1,20);self::assertNotWPError($out);self::assertTrue(DigiForge\Connector\Printify\NativePortContract::valid('workflow_view',$out));self::assertSame($v['record']['order_identity'],$out['items'][0]['identity']);self::assertTrue($out['items'][0]['manual_review_required']);self::assertFalse($out['external_execution_authorized']);if(is_file(DIGIFORGE_PATH.'includes/Connector/Printify/native-build.json'))self::assertNotContains('WORKFLOW',$port->contractManifest()['capabilities']);global $wpdb;$wpdb->query('DROP TABLE '.Schema::table());self::assertWPError($j->snapshot($s,1,20));
 }
 public function testSnapshotCannotHideAuditOnlyOrderAfterJournalRollback():void{
  $this->initialize();$r=$this->source();$s=$this->scope();$j=new Journal();self::assertNotWPError($j->register($s,1,$r['hash'],0,null));global $wpdb;self::assertSame(1,$wpdb->query('DELETE FROM '.Schema::table()));self::assertWPError($j->snapshot($s,1,20),'Retained audit-only order must never become an empty success.');
 }
 public function testSourceLossAtSnapshotCommitCannotEscapePostSnapshotCheck():void{
  $this->initialize();$r=$this->source();$s=$this->scope();$j=new Journal();self::assertNotWPError($j->register($s,1,$r['hash'],0,null));global $wpdb;$changed=false;
  // Model a committed independent authority change exactly at the snapshot boundary.
  $inject=static function(string $q)use(&$changed,$wpdb,$r):string{if($q==='COMMIT'&&!$changed){$changed=true;$wpdb->query('COMMIT');$name='digiforge_api_read_receipt_'.$r['hash'];$wpdb->delete($wpdb->options,['option_name'=>$name]);}return $q;};add_filter('query',$inject);
  try{self::assertWPError($j->snapshot($s,1,20),'Lost source authority after the snapshot must deny.');self::assertTrue($changed);}finally{remove_filter('query',$inject);}
 }
 public function testPolicyRevocationAtSnapshotCommitDeniesEvenWithCurrentConnectionAndSession():void{
  $this->initialize();$r=$this->source();$s=$this->scope();$j=new Journal();self::assertNotWPError($j->register($s,1,$r['hash'],0,null));$key='printify-api-read:'.$r['record']['plan_hash'];$policy=PrintifyApiApprovalFixture::checked(DigiForge\Security\ReviewedLocalEvidence::approved('policy','personalized_pod',$key));global $wpdb;$changed=false;$revoked=false;$fixture=$this->fixture;
  $inject=static function(string $q)use(&$changed,&$revoked,$wpdb,$fixture,$key,$policy):string{if($q==='COMMIT'&&!$changed){$changed=true;$wpdb->query('COMMIT');PortalAccessFixture::login($fixture->reviewer);$v=DigiForge\Security\ReviewedLocalEvidence::decide('policy','personalized_pod',$key,$policy['revision'],$policy['proposal_hash'],'REVOKED',wp_generate_uuid4());$revoked=!is_wp_error($v);$fixture->owner();}return $q;};add_filter('query',$inject);
  try{self::assertWPError($j->snapshot($s,1,20));self::assertTrue($changed);self::assertTrue($revoked,'Real synthetic policy revocation must complete at the boundary.');}finally{remove_filter('query',$inject);}
 }
}
