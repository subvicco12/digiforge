<?php
declare(strict_types=1);
use DigiForge\Connector\Printify\{NativeReadOnlyInspection as Inspection,NativeAuthorityAdapter,NativePanelAccessAdapter,NativeHeldJournal,NativeHeldJournalSchema,NativePortContract,ShopBinding,CredentialReference};
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/../support/NativePrintifyContractFixture.php';
final class NativeReadOnlyInspectionTest extends WP_UnitTestCase {
 private PrintifyApiApprovalFixture $f;private ShopBinding $scope;private array $context;
 protected function setUp():void{parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);$this->f=new PrintifyApiApprovalFixture();$this->f->boot();$this->f->setting('stop_all',true);$this->f->setting('automation_armed',false);$this->f->setting('activation_authorized',false);self::assertTrue(DigiForge\Core\Settings::protectProduction());$v=PrintifyApiApprovalFixture::checked((new DigiForge\POD\ScopedProviderConnection())->verified('personalized_pod',$this->f->integration,'printify.connection.read'));$this->scope=new ShopBinding(2,'personalized_pod',$this->f->integration,$v['external_shop_reference'],$v['environment'],$v['review_revision'],new CredentialReference($this->f->integration),$v['binding_hash'],$v['expires_at']);$a=PrintifyApiApprovalFixture::checked(NativeAuthorityAdapter::session(1));$this->context=['version'=>'1.0.0','view'=>'unknown','session_fingerprint'=>$a['fingerprint'],'csrf_token'=>wp_create_nonce(NativePanelAccessAdapter::nonceAction($this->scope,'unknown'))];}
 protected function tearDown():void{$this->f->restore();parent::tearDown();}
 public function testReadinessIsProtectedReadOnlyAndDoesNotCertifyEpochs():void{
  self::assertTrue(class_exists(Inspection::class),'Protected local inspection facade missing');$writes=0;$http=0;$filter=static function(string $sql)use(&$writes):string{if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql))++$writes;return $sql;};$network=static function()use(&$http){++$http;throw new RuntimeException('No HTTP');};add_filter('query',$filter);add_filter('pre_http_request',$network,PHP_INT_MAX);
  try{$r=Inspection::snapshot($this->scope,1,$this->context,['kind'=>'READINESS']);self::assertNotWPError($r);self::assertFalse($r['mutation_authorized']);self::assertFalse($r['independent_checkpoint_verified']);self::assertNull($r['recovery_epoch']);self::assertNull($r['checkpoint_epoch']);self::assertTrue($r['read_only']);self::assertFalse($r['network_request_permitted']);self::assertStringNotContainsString($this->context['csrf_token'],json_encode($r));self::assertStringNotContainsString($this->context['session_fingerprint'],json_encode($r));$h=$r['evidence_hash'];unset($r['evidence_hash']);self::assertSame($h,NativePortContract::hash($r));
   foreach([['kind'=>'REGISTER'],['kind'=>'READINESS','url'=>'https://example.invalid'],['kind'=>'ORDER_OBSERVATIONS','limit'=>21],['kind'=>'HELD_OPERATION','operation_id'=>'bad']]as $q)self::assertWPError(Inspection::snapshot($this->scope,1,$this->context,$q));$bad=$this->context;$bad['csrf_token']='invalid';self::assertWPError(Inspection::snapshot($this->scope,1,$bad,['kind'=>'READINESS']));self::assertWPError(Inspection::snapshot($this->scope,2,$this->context,['kind'=>'READINESS']));self::assertSame(0,$writes);self::assertSame(0,$http);$wrong=new ShopBinding($this->scope->shopId,$this->scope->shopKey,$this->scope->integrationId,$this->scope->providerShopId.'9',$this->scope->environment,$this->scope->revision,$this->scope->credential,$this->scope->bindingHash,$this->scope->expiresAt);self::assertWPError(Inspection::snapshot($wrong,1,$this->context,['kind'=>'READINESS']));
  }finally{remove_filter('query',$filter);remove_filter('pre_http_request',$network,PHP_INT_MAX);}
 }
 public function testHeldInspectionUsesActualJournalWithoutRearmingOrExportingFence():void{
  self::assertTrue(class_exists(Inspection::class));global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');foreach(NativeHeldJournalSchema::statements()as $sql)self::assertNotFalse($wpdb->query(preg_replace('/^CREATE TABLE /','CREATE TABLE IF NOT EXISTS ',$sql)));self::assertNotFalse($wpdb->query(preg_replace('/^CREATE TABLE /','CREATE TABLE IF NOT EXISTS ',DigiForge\Connector\Printify\NativeOrderSourceSchema::statement())));$oc=$this->context;$oc['view']='orders';$oc['csrf_token']=wp_create_nonce(NativePanelAccessAdapter::nonceAction($this->scope,'orders'));$orders=Inspection::snapshot($this->scope,1,$oc,['kind'=>'ORDER_OBSERVATIONS','limit'=>20]);self::assertNotWPError($orders);self::assertSame([],$orders['observation']['items']);$b=NativePrintifyContractFixture::binding();foreach(['scope_fingerprint'=>$this->scope->fingerprint(),'integration_id'=>$this->scope->integrationId,'credential_reference'=>$this->scope->integrationId,'provider_shop_id'=>$this->scope->providerShopId,'environment'=>$this->scope->environment,'authorization_revision'=>$this->scope->revision]as $k=>$v)$b[$k]=$v;$b['operation_id']=hash('sha256',wp_generate_uuid4());$j=new NativeHeldJournal();$first=PrintifyApiApprovalFixture::checked($j->register($b,hash('sha256',$b['operation_id'].':fixture')));$q=['kind'=>'HELD_OPERATION','operation_id'=>$b['operation_id']];$delayed=false;$inspectionWrites=0;$slow=static function(string $sql)use(&$delayed,&$inspectionWrites):string{if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql))++$inspectionWrites;if(!$delayed&&$sql==='START TRANSACTION READ ONLY'){$delayed=true;usleep(1100000);}return $sql;};add_filter('query',$slow);try{$r=Inspection::snapshot($this->scope,1,$this->context,$q);}finally{remove_filter('query',$slow);}self::assertTrue($delayed);self::assertSame(0,$inspectionWrites);self::assertNotWPError($r);self::assertSame('HELD',$r['observation']['state']);self::assertSame($first,$j->read($b['operation_id'],'personalized_pod',1));$f=PrintifyApiApprovalFixture::checked($j->transition($b['operation_id'],'personalized_pod',1,1,'FENCE'));$u=PrintifyApiApprovalFixture::checked($j->transition($b['operation_id'],'personalized_pod',1,2,'UNKNOWN'));$r=Inspection::snapshot($this->scope,1,$this->context,$q);self::assertNotWPError($r);self::assertSame('UNKNOWN',$r['observation']['state']);self::assertTrue($r['observation']['fence_present']);self::assertArrayNotHasKey('fence_hash',$r['observation']);self::assertSame($u,$j->read($b['operation_id'],'personalized_pod',1));$other=$b;$other['operation_id']=hash('sha256',wp_generate_uuid4());$other['integration_id']++;$other['credential_reference']++;PrintifyApiApprovalFixture::checked($j->register($other,hash('sha256',$other['operation_id'].':fixture')));self::assertWPError(Inspection::snapshot($this->scope,1,$this->context,['kind'=>'HELD_OPERATION','operation_id'=>$other['operation_id']]));wp_set_current_user(0);self::assertWPError(Inspection::snapshot($this->scope,1,$this->context,$q));$this->f->owner();$wpdb->query('START TRANSACTION');
 }
 public function testScopedWriterBindsCurrentOwnerAndOneUseFence():void {
  global $wpdb;
  foreach(NativeHeldJournalSchema::statements() as $sql)self::assertNotFalse($wpdb->query(preg_replace('/^CREATE TABLE /','CREATE TABLE IF NOT EXISTS ',$sql)));
  $binding=array_replace(NativePrintifyContractFixture::binding(),[
   'operation_id'=>hash('sha256',wp_generate_uuid4()),'scope_fingerprint'=>$this->scope->fingerprint(),
   'integration_id'=>$this->scope->integrationId,'credential_reference'=>$this->scope->integrationId,
   'provider_shop_id'=>$this->scope->providerShopId,'environment'=>$this->scope->environment,
   'authorization_revision'=>$this->scope->revision,
  ]);
  $key=hash('sha256','scoped:'.$binding['operation_id']);
  $journal=new NativeHeldJournal();
  $stale=array_replace($binding,['authorization_revision'=>$binding['authorization_revision']+1]);
  self::assertWPError($journal->prepareScoped($stale,$key));
  $held=PrintifyApiApprovalFixture::checked($journal->prepareScoped($binding,$key));
  self::assertTrue($held['record']['scope_recheck_required']);
  self::assertFalse($held['record']['dispatch_permitted']);
  self::assertWPError($journal->prepareScoped($binding,$key));
  self::assertWPError($journal->transition($binding['operation_id'],$binding['shop_key'],1,1,'FENCE'));
  self::assertWPError($journal->fenceScoped($stale,1,$held['event_hash']));
  $wrongPayload=array_replace($binding,['payload_fingerprint'=>str_repeat('f',64)]);
  self::assertWPError($journal->fenceScoped($wrongPayload,1,$held['event_hash']));
  $fenced=PrintifyApiApprovalFixture::checked($journal->fenceScoped($binding,1,$held['event_hash']));
  self::assertSame('FENCED',$fenced['record']['state']);
  self::assertFalse($fenced['record']['dispatch_permitted']);
  self::assertFalse($fenced['record']['independent_checkpoint_verified']);
  self::assertWPError($journal->fenceScoped($binding,1,$held['event_hash']));
  $unknown=PrintifyApiApprovalFixture::checked($journal->transition($binding['operation_id'],$binding['shop_key'],1,2,'UNKNOWN'));
  self::assertSame('UNKNOWN',$unknown['record']['state']);
  self::assertSame($unknown,(new NativeHeldJournal())->read($binding['operation_id'],$binding['shop_key'],1));
  $port=DigiForge\Connector\Printify\NativeBridge::connect('1.0.0');
  self::assertNotWPError($port);
  $wire=DigiForge\POD\PrintifyProductRequest::prepare('PRODUCT_UPDATE',$binding['provider_shop_id'],[
   'product_id'=>'fixture-product','title'=>'Held fixture title',
  ]);
  $q=NativePrintifyContractFixture::mutation('REGISTER');
  $q['context']=array_replace($q['context'],['logical_reference'=>'fixture-product','authorization_revision'=>$this->scope->revision]);
  $q['duplicate_key']=NativePortContract::hash(['PRODUCT_UPDATE',$binding['shop_key'],$binding['integration_id'],
   $binding['provider_shop_id'],'test','fixture-product:1']);
  $q['data']=['prepared'=>['core_request'=>$wire,'scope_fingerprint'=>$binding['scope_fingerprint'],'external_execution_authorized'=>false]];
  $binding['payload_fingerprint']=NativePortContract::hash(array_intersect_key($wire,array_flip(['method','endpoint','body'])));
  $binding['operation_id']=NativePortContract::hash(['DF_PRINTIFY_PREPARATION_V1',$q['duplicate_key'],$binding['payload_fingerprint']]);
  $binding['intent_fingerprint']=NativePortContract::hash([$binding['operation_id'],$binding['scope_fingerprint'],$q['context'],$q['data']['prepared']]);
  $q['binding']=$binding;
  $substituted=$q;
  $substituted['data']['prepared']['core_request']['body']['title']='Substituted title';
  self::assertWPError($port->stageHeldProduct($substituted));
  // An attacker can recompute unkeyed digests: aliases still cannot create another held slot.
  $alias=$q;$alias['context']['logical_reference']='fixture-alias';
  $alias['duplicate_key']=NativePortContract::hash(['PRODUCT_UPDATE',$binding['shop_key'],$binding['integration_id'],
   $binding['provider_shop_id'],'test','fixture-alias:1']);
  $alias['binding']['operation_id']=NativePortContract::hash(['DF_PRINTIFY_PREPARATION_V1',$alias['duplicate_key'],$binding['payload_fingerprint']]);
  $alias['binding']['intent_fingerprint']=NativePortContract::hash([$alias['binding']['operation_id'],
   $binding['scope_fingerprint'],$alias['context'],$alias['data']['prepared']]);
  self::assertWPError($port->stageHeldProduct($alias));
  $malformed=$q;
  $badWire=$wire;$badWire['body']['unsupported_payload']='data';
  $badWire['fingerprint']=NativePortContract::hash(array_diff_key($badWire,array_flip(['fingerprint','external_execution_authorized'])));
  self::assertTrue(DigiForge\POD\PrintifyProductRequest::intact($badWire));
  $malformed['data']['prepared']['core_request']=$badWire;
  $malformed['binding']['payload_fingerprint']=NativePortContract::hash(array_intersect_key($badWire,array_flip(['method','endpoint','body'])));
  $malformed['binding']['operation_id']=NativePortContract::hash(['DF_PRINTIFY_PREPARATION_V1',$q['duplicate_key'],$malformed['binding']['payload_fingerprint']]);
  $malformed['binding']['intent_fingerprint']=NativePortContract::hash([$malformed['binding']['operation_id'],$binding['scope_fingerprint'],$q['context'],$malformed['data']['prepared']]);
  self::assertWPError($port->stageHeldProduct($malformed));
  self::assertWPError($port->exchange($q));
  $staged=PrintifyApiApprovalFixture::checked($port->stageHeldProduct($q));
  self::assertSame('HELD',$staged['record']['state']);
  self::assertFalse($staged['record']['approval_verified']);
  self::assertWPError($port->stageHeldProduct($q));
  $consumed=PrintifyApiApprovalFixture::checked($port->fenceHeldProduct($q,1,$staged['event_hash']));
  self::assertSame('FENCED',$consumed['record']['state']);
  self::assertFalse($consumed['record']['dispatch_permitted']);
  self::assertWPError($port->fenceHeldProduct($q,1,$staged['event_hash']));
  $wpdb->query('START TRANSACTION');
 }

}
