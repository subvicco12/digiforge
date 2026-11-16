<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/PrintifySyntheticLeaseClock.php';
use DigiForge\POD\{PrintifyApiReadWorkflow,PrintifyLifecycleWorkflow,PrintifyControlledTransport};

final class PrintifyWorkflowLeaseTest extends WP_UnitTestCase
{
 private PrintifyApiApprovalFixture $fixture;
 public static function set_up_before_class():void { parent::set_up_before_class();PrintifyApiApprovalFixture::checked(DigiForge\Database\ProviderConnectionSchema::migrateIfNeeded()); }
 protected function setUp():void { parent::setUp();PrintifySyntheticLeaseClock::$now=null;$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot(); }
 protected function tearDown():void { PrintifySyntheticLeaseClock::$now=null;try{$this->fixture->restore();}finally{parent::tearDown();} }
 private function readPlan():array {
  $w=new PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'SHOPS_READ',[]));
  return [$p,$this->fixture->approve($w,$p,'printify-api-read')];
 }
 private static function shops():array { return PrintifyApiApprovalFixture::response([['id'=>12,'title'=>'Synthetic fixture','sales_channel'=>'etsy']]); }
 private function advanceOn(string $event,int $expiry,callable $work):mixed {
  $hook=static function(string $query)use($event,$expiry):string{if(str_contains($query,$event))PrintifySyntheticLeaseClock::$now=$expiry;return $query;};
  add_filter('query',$hook);
  try { $result=$work();self::assertSame($expiry,PrintifySyntheticLeaseClock::$now,'Timing injection must run');return $result; }
  finally { remove_filter('query',$hook); }
 }
 public function testReadLeaseIsClosedAtExactExpiry():void {
  [$p,$a]=$this->readPlan();PrintifySyntheticLeaseClock::$now=$a['record']['expires_at'];$calls=0;
  $t=new PrintifyControlledTransport(static function()use(&$calls){++$calls;return self::shops();});
  $r=$t->executeApiRead($p['hash'],$a['hash']);self::assertWPError($r);self::assertSame(0,$calls);
 }
 public function testDeferredReadLeaseExpiryBeforeCredentialBoundaryNeverSends():void {
  [$p,$a]=$this->readPlan();$calls=0;$t=new PrintifyControlledTransport(static function()use(&$calls){++$calls;return self::shops();});
  $this->advanceOn('printify_api_read_reserved',$a['record']['expires_at']+1,fn()=>$t->executeApiRead($p['hash'],$a['hash']));self::assertSame(0,$calls);
 }
 public function testQuotaPersistenceCannotCrossReadLeaseBeforeSend():void {
  [$p,$a]=$this->readPlan();$calls=0;$t=new PrintifyControlledTransport(static function()use(&$calls){++$calls;return self::shops();});
  $this->advanceOn('df_pq_a_',$a['record']['expires_at']+1,fn()=>$t->executeApiRead($p['hash'],$a['hash']));self::assertSame(0,$calls);
 }
 public function testExpiredReadResponseBecomesUnknownWithoutDataOrReplay():void {
  [$p,$a]=$this->readPlan();$calls=0;$t=new PrintifyControlledTransport(static function()use(&$calls,$a){++$calls;PrintifySyntheticLeaseClock::$now=$a['record']['expires_at']+1;return self::shops();});
  $r=PrintifyApiApprovalFixture::checked($t->executeApiRead($p['hash'],$a['hash']));self::assertSame('UNKNOWN',$r['record']['status']);self::assertNull($r['record']['data']);self::assertSame(1,$calls);
  self::assertWPError($t->executeApiRead($p['hash'],$a['hash']));self::assertSame(1,$calls);
 }
 public function testSafeReadRetryCannotContinueAfterLeaseExpires():void {
  [$p,$a]=$this->readPlan();$calls=0;$t=new PrintifyControlledTransport(static function()use(&$calls,$a){++$calls;PrintifySyntheticLeaseClock::$now=$a['record']['expires_at']+1;return PrintifyApiApprovalFixture::response([],503);});
  $t->executeApiRead($p['hash'],$a['hash']);self::assertSame(1,$calls);
 }
 public function testFreshReadStillSucceedsWithOneScopedSyntheticResponse():void {
  [$p,$a]=$this->readPlan();$calls=0;$t=new PrintifyControlledTransport(static function()use(&$calls){++$calls;return self::shops();});
  $r=PrintifyApiApprovalFixture::checked($t->executeApiRead($p['hash'],$a['hash']));self::assertSame('SUCCEEDED',$r['record']['status']);self::assertSame(1,$calls);self::assertSame(12,$r['record']['data']['shops'][0]['id']);
 }
 public function testLifecycleLeaseExpiryAfterReservationNeverSends():void {
  $w=new PrintifyLifecycleWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'WEBHOOK_READ',[]));$a=$this->fixture->approve($w,$p,'printify-lifecycle');$calls=0;
  $t=new PrintifyControlledTransport(static function()use(&$calls){++$calls;return PrintifyApiApprovalFixture::response([]);});
  $this->advanceOn('printify_lifecycle_reserved',$a['record']['expires_at']+1,fn()=>$t->executeLifecycle($p['hash'],$a['hash']));self::assertSame(0,$calls);
 }
 public function testCancellationLeaseExpiryDuringQuotaNeverSends():void {
  $read=new PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($read->prepare('personalized_pod',$this->fixture->integration,'ORDER_READ',['order_id'=>'o1']));$a=$this->fixture->approve($read,$p,'printify-api-read');
  $receipt=PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response(['id'=>'o1','status'=>'pending','line_items'=>[['id'=>'l1','product_id'=>'p1','variant_id'=>3,'quantity'=>1,'status'=>'pending']]])))->executeApiRead($p['hash'],$a['hash']));
  $w=new PrintifyLifecycleWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'LINE_ITEM_CANCEL',['order_id'=>'o1','line_item_id'=>'l1'],$receipt['hash']));$a=$this->fixture->approve($w,$p,'printify-lifecycle');
  $short=$a['record'];unset($short['seal']);$short['expires_at']=$short['issued_at']+30;$save=new ReflectionMethod($w,'save');$save->setAccessible(true);$a=PrintifyApiApprovalFixture::checked($save->invoke($w,'authorization',$short));$calls=0;
  $t=new PrintifyControlledTransport(static function()use(&$calls){++$calls;return ['response'=>['code'=>204],'headers'=>[],'body'=>''];});
  $this->advanceOn('df_pq_a_',$short['expires_at']+1,fn()=>$t->executeLifecycle($p['hash'],$a['hash']));self::assertSame(0,$calls);
 }
 public function testCredentialAdmissionDenialPrecedesSecretReadAndConsumer():void {
  [$p,$a]=$this->readPlan();$secretReads=0;$called=false;
  $hook=static function(string $query)use(&$secretReads):string{if(str_contains($query,'SELECT ciphertext'))++$secretReads;return $query;};
  add_filter('query',$hook);
  try{$r=(new DigiForge\POD\PrintifyScopedCredentialRetriever())->consume($this->fixture->integration,static function()use(&$called){$called=true;return [];},$p['record']['connection_scope'],static fn()=>new WP_Error('synthetic_revoked'));}
  finally{remove_filter('query',$hook);}
  self::assertWPError($r);self::assertSame('synthetic_revoked',$r->get_error_code());self::assertSame(0,$secretReads);self::assertFalse($called);
 }
 public function testNewApprovalRevisionCannotReuseOldReadAuthorization():void {
  [$p,$a]=$this->readPlan();$review=DigiForge\Security\ReviewedLocalEvidence::class;$key='printify-api-read:'.$p['hash'];
  $prior=PrintifyApiApprovalFixture::checked($review::approved('policy','personalized_pod',$key));
  $next=PrintifyApiApprovalFixture::checked($review::propose('policy','personalized_pod',$key,$prior['payload'],time(),time()+1200,wp_generate_uuid4(),$prior['revision']));
  PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked($review::decide('policy','personalized_pod',$key,$next['revision'],$next['proposal_hash'],'APPROVED',wp_generate_uuid4()));$this->fixture->owner();
  $calls=0;$t=new PrintifyControlledTransport(static function()use(&$calls){++$calls;return self::shops();});
  self::assertWPError($t->executeApiRead($p['hash'],$a['hash']));self::assertSame(0,$calls);
 }
 public function testStopDuringQuotaDeniesSendAndKeepsOneUseClaimConsumed():void {
  [$p,$a]=$this->readPlan();$calls=0;$changed=false;
  $hook=function(string $query)use(&$changed):string{if(!$changed&&str_contains($query,'df_pq_a_')){$changed=true;$this->fixture->setting('stop_all',true);}return $query;};
  $t=new PrintifyControlledTransport(static function()use(&$calls){++$calls;return self::shops();});add_filter('query',$hook);
  try{$t->executeApiRead($p['hash'],$a['hash']);}finally{remove_filter('query',$hook);}
  self::assertTrue($changed);self::assertSame(0,$calls);$this->fixture->setting('stop_all',false);
  self::assertWPError($t->executeApiRead($p['hash'],$a['hash']));self::assertSame(0,$calls);
 }
 public function testPostConsumerExpiryDiscardsResultWithoutExposingCredential():void {
  [$p,$a]=$this->readPlan();$calls=0;
  $r=(new DigiForge\POD\PrintifyScopedCredentialRetriever())->consume($this->fixture->integration,static function(string $token)use(&$calls,$a){++$calls;PrintifySyntheticLeaseClock::$now=$a['record']['expires_at'];return ['credential'=>$token];},$p['record']['connection_scope'],static fn()=>PrintifySyntheticLeaseClock::$now===null?true:new WP_Error('synthetic_expired'));
  self::assertSame(1,$calls);self::assertWPError($r);self::assertSame('synthetic_expired',$r->get_error_code());self::assertStringNotContainsString('synthetic-api-material',wp_json_encode($r));
 }

 public function testExpiryInsideFinalApprovalQueryCannotReachSender():void {
  [$p,$a]=$this->readPlan();$armed=false;$expired=false;$calls=0;
  $hook=static function(string $query)use(&$armed,&$expired,$a):string{if(str_contains($query,'df_pq_a_'))$armed=true;if($armed&&str_contains($query,'digiforge_api_read_plan_')){$expired=true;PrintifySyntheticLeaseClock::$now=$a['record']['expires_at'];}return $query;};
  $t=new PrintifyControlledTransport(static function()use(&$calls){++$calls;return self::shops();});add_filter('query',$hook);
  try{$t->executeApiRead($p['hash'],$a['hash']);}finally{remove_filter('query',$hook);}
  self::assertTrue($expired);self::assertSame(0,$calls);
 }
 public function testExpiryInsideLastCredentialBindingQueryDiscardsSuccess():void {
  [$p,$a]=$this->readPlan();$afterConsumer=false;$expired=false;
  $hook=static function(string $query)use(&$afterConsumer,&$expired,$a):string{if($afterConsumer&&str_starts_with($query,'SELECT')){$expired=true;PrintifySyntheticLeaseClock::$now=$a['record']['expires_at'];}return $query;};
  add_filter('query',$hook);
  try{$r=(new DigiForge\POD\PrintifyScopedCredentialRetriever())->consume($this->fixture->integration,static function()use(&$afterConsumer){$afterConsumer=true;return ['status'=>'SUCCEEDED'];},$p['record']['connection_scope'],static fn()=>PrintifySyntheticLeaseClock::$now===null?true:new WP_Error('synthetic_expired'));}
  finally{remove_filter('query',$hook);}
  self::assertTrue($expired);self::assertWPError($r);
 }

 public function testControlledMutationPermitIsExpiredAtExactBoundary():void {
  PrintifySyntheticLeaseClock::$now=1000;
  $permit=['state'=>'ADAPTER_CALL_PERMITTED','nonce_consumed'=>true,'external_execution_performed'=>false,'action'=>'PROVIDER_ORDER_SUBMIT','authorization'=>['issued_at'=>900,'expires_at'=>1000]];
  $r=DigiForge\POD\PrintifyLiveTransportInterlock::authorize($permit,['action'=>'PROVIDER_ORDER_SUBMIT']);
  self::assertWPError($r);self::assertSame('digiforge_printify_interlock_expired',$r->get_error_code());
 }

}
