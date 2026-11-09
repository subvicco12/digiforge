<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
use DigiForge\POD\{PrintifyAcceptanceBridge,PrintifyApiReadWorkflow,PrintifyControlledTransport};
final class PrintifyAcceptanceBridgeTest extends WP_UnitTestCase {
 private PrintifyApiApprovalFixture $f;
 public function set_up():void{parent::set_up();$this->f=new PrintifyApiApprovalFixture();$this->f->boot();}
 public function tear_down():void{global $wpdb;$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'df_acceptance_%'");$this->f->restore();parent::tear_down();}
 private function setupBridge():array {
  $w=new PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->f->integration,'SHOPS_READ',[]));$auth=$this->f->approve($w,$p,'printify-api-read');$b=new PrintifyAcceptanceBridge();$s=PrintifyApiApprovalFixture::checked($b->prepare($this->f->integration,[['pipeline'=>'read','plan_hash'=>$p['hash']]]));return [$b,$s,$auth];
 }
 public function testDistinctApprovalAndExistingLocksRemainAuthoritative():void {
  self::assertTrue(class_exists(PrintifyAcceptanceBridge::class),'Bridge implementation required');[$b,$s,$a]=$this->setupBridge();self::assertWPError($b->authorize($s['hash']));$this->f->approve($b,$s,'printify-acceptance');$calls=0;$t=new PrintifyControlledTransport(function()use(&$calls){$calls++;return PrintifyApiApprovalFixture::response([['id'=>12,'title'=>'private shop','sales_channel'=>'etsy']]);});
  $this->f->setting('stop_all',true);self::assertWPError($t->executeAcceptance($s['hash'],0,$a['hash']));self::assertSame(0,$calls);
 }
 public function testSanitizedReceiptsReplayRevocationAndSessionBinding():void {
  self::assertTrue(class_exists(PrintifyAcceptanceBridge::class),'Bridge implementation required');[$b,$s,$a]=$this->setupBridge();$this->f->approve($b,$s,'printify-acceptance');$calls=0;$t=new PrintifyControlledTransport(function($url,$args)use(&$calls){$calls++;self::assertSame('GET',$args['method']);self::assertSame(0,$args['redirection']);return PrintifyApiApprovalFixture::response([['id'=>12,'title'=>'private shop','sales_channel'=>'etsy']]);});$r=$t->executeAcceptance($s['hash'],0,$a['hash']);self::assertNotWPError($r);self::assertSame(200,$r['receipt']['http_status']);self::assertStringNotContainsString('private shop',json_encode($r));self::assertSame($r,$b->receipt($r['hash']));self::assertWPError($t->executeAcceptance($s['hash'],0,$a['hash']));self::assertSame(1,$calls);self::assertTrue($b->revoke($s['hash'])===true);self::assertWPError($b->authorize($s['hash']));self::assertSame($r,$b->receipt($r['hash']));
 }
 public function testUnboundMappingAndForeignActorsDenied():void {
  self::assertTrue(class_exists(PrintifyAcceptanceBridge::class),'Bridge implementation required');[$b,$s]=$this->setupBridge();$this->f->approve($b,$s,'printify-acceptance');wp_set_current_user($this->f->reviewer);self::assertWPError($b->authorize($s['hash']));$this->f->owner();self::assertWPError($b->prepare(99999,[['pipeline'=>'read','plan_hash'=>str_repeat('a',64)]]));
 }
 /** @dataProvider failedProviderResponses */
 public function testProviderFailuresNeverLeakValuesOrRetry(int $status,string $body,string $reason):void {
  [$b,$s,$a]=$this->setupBridge();$this->f->approve($b,$s,'printify-acceptance');$calls=0;
  $t=new PrintifyControlledTransport(function()use(&$calls,$status,$body){$calls++;return ['response'=>['code'=>$status],'headers'=>['retry-after'=>$status===429?'45':'','location'=>'https://evil.test/secret'],'body'=>$body];});
  $r=$t->executeAcceptance($s['hash'],0,$a['hash']);self::assertNotWPError($r);self::assertSame(1,$calls);self::assertSame($status,$r['receipt']['http_status']);self::assertSame($reason,$r['receipt']['reason']);self::assertSame('UNVERIFIED',$r['receipt']['compatibility']);self::assertStringNotContainsString('buyer@example.test',json_encode($r));self::assertStringNotContainsString('evil.test',json_encode($r));self::assertWPError($t->executeAcceptance($s['hash'],0,$a['hash']));if($status===429)self::assertSame(45,$r['receipt']['retry_after_seconds']);
 }
 public static function failedProviderResponses():array{return [[401,'{"email":"buyer@example.test"}','CREDENTIAL_REJECTED'],[403,'{}','SCOPE_REJECTED'],[429,'{}','RATE_LIMITED'],[500,'{}','PROVIDER_UNAVAILABLE'],[302,'{}','UPSTREAM_OR_SCHEMA_REJECTED'],[200,'not json buyer@example.test','INVALID_JSON'],[200,str_repeat('x',1048577),'OVERSIZED_RESPONSE'],[200,'{}','UPSTREAM_OR_SCHEMA_REJECTED']];}
 public function testExpiryTamperingAndBrowserChangeFailClosed():void {
  [$b,$s]=$this->setupBridge();$this->f->approve($b,$s,'printify-acceptance');$cookies=$_COOKIE;$_COOKIE=[];self::assertWPError($b->authorize($s['hash']));$_COOKIE=$cookies;
  $load=new ReflectionMethod($b,'load');$r=$load->invoke($b,'session',$s['hash']);$r['issued_at']=time()-400;$r['expires_at']=time()-100;$save=new ReflectionMethod($b,'save');$expired=$save->invoke($b,'session',$r);self::assertWPError($b->authorize($expired['hash']));
  update_option('df_acceptance_session_'.$s['hash'],'tampered');self::assertWPError($b->authorize($s['hash']));
 }
 public function testBudgetsAcrossSessions():void {
  $calls=0;$t=new PrintifyControlledTransport(function()use(&$calls){$calls++;return PrintifyApiApprovalFixture::response([['id'=>12,'title'=>'Synthetic','sales_channel'=>'etsy']]);});
  for($i=0;$i<4;$i++){[$b,$s,$a]=$this->setupBridge();$this->f->approve($b,$s,'printify-acceptance');$r=$t->executeAcceptance($s['hash'],0,$a['hash']);if($i<3)self::assertNotWPError($r);else self::assertWPError($r);}self::assertSame(3,$calls);
 }
 public function testOwnerOnlyAbilityDiscoveryAndStrictInputs():void {
  $ability=wp_get_ability('digiforge/printify-acceptance-execute');self::assertNotNull($ability);$r=(new \DigiForge\POD\PrintifyAcceptanceAbilities())->invoke('execute',['url'=>'https://evil.test']);self::assertWPError($r);wp_set_current_user(0);self::assertFalse(PrintifyAcceptanceBridge::permission());self::assertWPError($ability->execute(['session_hash'=>str_repeat('a',64),'slot'=>0,'authorization_hash'=>str_repeat('b',64)]));
 }

 public function testRevocationDuringResponseAndConcurrentReservation():void {
  [$b,$s,$a]=$this->setupBridge();$this->f->approve($b,$s,'printify-acceptance');$r=(new PrintifyControlledTransport(function()use($b,$s){self::assertTrue($b->revoke($s['hash'])===true);return PrintifyApiApprovalFixture::response([['id'=>12,'title'=>'Secret','sales_channel'=>'etsy']]);}))->executeAcceptance($s['hash'],0,$a['hash']);self::assertNotWPError($r);self::assertSame('UNVERIFIED',$r['receipt']['compatibility']);self::assertFalse($r['receipt']['observations']['values_disclosed']);
  [$b,$s,$a]=$this->setupBridge();$this->f->approve($b,$s,'printify-acceptance');$load=new ReflectionMethod($b,'load');$session=$load->invoke($b,'session',$s['hash']);$other=new mysqli(str_contains(DB_HOST,':/')?'localhost':explode(':',DB_HOST)[0],DB_USER,DB_PASSWORD,DB_NAME,str_contains(DB_HOST,':/')?0:(int)(explode(':',DB_HOST)[1]??3306),str_contains(DB_HOST,':/')?substr(DB_HOST,strpos(DB_HOST,':')+1):null);$key='dfa_'.substr($session['account_sha256'],0,40);$other->query("SELECT GET_LOCK('".$other->real_escape_string($key)."',0)");$calls=0;try{$r=(new PrintifyControlledTransport(function()use(&$calls){$calls++;return [];}))->executeAcceptance($s['hash'],0,$a['hash']);self::assertWPError($r);self::assertSame('printify_acceptance_concurrent',$r->get_error_code());self::assertSame(0,$calls);}finally{$other->query("SELECT RELEASE_LOCK('".$other->real_escape_string($key)."')");$other->close();}
 }
 public function testMutationPlanAndMissingCredentialCannotExecute():void {
  $b=new PrintifyAcceptanceBridge();$w=new \DigiForge\POD\PrintifyLifecycleWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->f->integration,'SHIPPING_QUOTE',['order'=>PrintifyApiApprovalFixture::order()]));self::assertWPError($b->prepare($this->f->integration,[['pipeline'=>'lifecycle','plan_hash'=>$p['hash']]]));
  [$b,$s,$a]=$this->setupBridge();$this->f->approve($b,$s,'printify-acceptance');global $wpdb;$wpdb->delete(\DigiForge\Database\Tables::integration_secrets(),['integration_id'=>$this->f->integration]);$calls=0;$r=(new PrintifyControlledTransport(function()use(&$calls){$calls++;return [];}))->executeAcceptance($s['hash'],0,$a['hash']);self::assertSame(0,$calls);self::assertTrue(is_wp_error($r)||$r['receipt']['compatibility']==='UNVERIFIED');
 }

 public function testRollingWindowExpiryAndSessionTargetLimit():void {
  [$b,$s,$a]=$this->setupBridge();$this->f->approve($b,$s,'printify-acceptance');$load=new ReflectionMethod($b,'load');$session=$load->invoke($b,'session',$s['hash']);$key='df_acceptance_window_'.$session['account_sha256'];add_option($key,wp_json_encode([time()-59,time()-59,time()-59]),'',false);$calls=0;$t=new PrintifyControlledTransport(function()use(&$calls){$calls++;return PrintifyApiApprovalFixture::response([['id'=>12,'title'=>'Private','sales_channel'=>'etsy']]);});$r=$t->executeAcceptance($s['hash'],0,$a['hash']);self::assertWPError($r);self::assertSame('printify_acceptance_quota',$r->get_error_code());self::assertSame(0,$calls);update_option($key,wp_json_encode([time()-61,time()-61,time()-61]));self::assertNotWPError($t->executeAcceptance($s['hash'],0,$a['hash']));self::assertSame(1,$calls);self::assertWPError($b->prepare($this->f->integration,array_fill(0,11,$session['slots'][0]['target'])));
 }

}
