<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
use DigiForge\POD\{PrintifyApiReadWorkflow,PrintifyControlledTransport};

final class PrintifyAtomicOptionClaimTest extends WP_UnitTestCase
{
 private PrintifyApiApprovalFixture $fixture;
 public static function set_up_before_class():void { parent::set_up_before_class();PrintifyApiApprovalFixture::checked(DigiForge\Database\ProviderConnectionSchema::migrateIfNeeded()); }
 protected function setUp():void { parent::setUp();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot(); }
 protected function tearDown():void { try{$this->fixture->restore();}finally{parent::tearDown();} }
 public function testTwoInterleavedReadAuthorizationsHaveExactlyOnePlanWinner():void {
  $w=new PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'SHOPS_READ',[]));
  $first=$this->fixture->approve($w,$p,'printify-api-read');$second=PrintifyApiApprovalFixture::checked($w->authorize($p['hash']));
  self::assertNotSame($first['hash'],$second['hash']);$key='digiforge_api_read_claim_'.$p['hash'];$interleaved=false;$calls=0;$inner=null;
  $transport=new PrintifyControlledTransport(static function()use(&$calls){++$calls;return PrintifyApiApprovalFixture::response([['id'=>12,'title'=>'Synthetic','sales_channel'=>'etsy']]);});
  $hook=static function(string $sql)use(&$interleaved,&$inner,$key,$transport,$p,$second):string{
   if(!$interleaved&&preg_match('/^INSERT\b/i',$sql)&&str_contains($sql,$key)){
    $interleaved=true;global $wpdb;$primary=$wpdb;$worker=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$worker->set_prefix($primary->prefix);$wpdb=$worker;
    try{$inner=$transport->executeApiRead($p['hash'],$second['hash']);}finally{$wpdb=$primary;$worker->close();}
   }
   return $sql;
  };
  add_filter('query',$hook);
  try{$outer=$transport->executeApiRead($p['hash'],$first['hash']);}finally{remove_filter('query',$hook);}
  self::assertTrue($interleaved,'Both callers must reach the pre-insert race boundary');self::assertNotWPError($inner);self::assertSame('SUCCEEDED',$inner['record']['status']);
  self::assertSame(1,$calls,'Only one authorization may dispatch for this prepared plan');self::assertWPError($outer);
  global $wpdb;self::assertSame($second['hash'],$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)),'Losing writer must not overwrite winner identity');
 }
 public function testIdenticalCancellationIntentsAcrossPlansCannotBothSend():void {
  $read=new PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($read->prepare('personalized_pod',$this->fixture->integration,'ORDER_READ',['order_id'=>'o1']));$a=$this->fixture->approve($read,$p,'printify-api-read');
  $r=PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response(['id'=>'o1','status'=>'pending','line_items'=>[['id'=>'l1','product_id'=>'p1','variant_id'=>3,'quantity'=>1,'status'=>'pending']]])))->executeApiRead($p['hash'],$a['hash']));
  $w=new DigiForge\POD\PrintifyLifecycleWorkflow();$input=['order_id'=>'o1','line_item_id'=>'l1'];$one=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'LINE_ITEM_CANCEL',$input,$r['hash']));$two=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'LINE_ITEM_CANCEL',$input,$r['hash']));
  self::assertNotSame($one['hash'],$two['hash']);self::assertSame($one['record']['request']['fingerprint'],$two['record']['request']['fingerprint']);$a=$this->fixture->approve($w,$one,'printify-lifecycle');$b=$this->fixture->approve($w,$two,'printify-lifecycle');
  $key='digiforge_pfy_lifecycle_claim_'.hash('sha256',$one['record']['connection_scope']['binding_hash'].':'.$one['record']['request']['fingerprint']);$interleaved=false;$calls=0;$inner=null;
  $transport=new PrintifyControlledTransport(static function()use(&$calls){++$calls;return ['response'=>['code'=>204],'headers'=>[],'body'=>''];});
  $hook=static function(string $sql)use(&$interleaved,&$inner,$key,$transport,$two,$b):string{if(!$interleaved&&preg_match('/^INSERT\b/i',$sql)&&str_contains($sql,$key)){$interleaved=true;$inner=$transport->executeLifecycle($two['hash'],$b['hash']);}return $sql;};
  add_filter('query',$hook);try{$outer=$transport->executeLifecycle($one['hash'],$a['hash']);}finally{remove_filter('query',$hook);}
  self::assertTrue($interleaved);self::assertNotWPError($inner);self::assertSame(1,$calls,'Two plans with identical cancellation intent must not both dispatch');self::assertWPError($outer);
  global $wpdb;self::assertSame($b['hash'],$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)));
 }

 private function reserve(string $key,string $value):bool {
  self::assertTrue(method_exists(DigiForge\POD\PrintifyOperationClaim::class,'reserveIndex'),'Insert-only claim primitive must exist');
  return DigiForge\POD\PrintifyOperationClaim::reserveIndex($key,$value);
 }
 private function key():string { return 'digiforge_api_read_claim_'.hash('sha256',wp_generate_uuid4()); }
 public function testWinnerIsNeverOverwrittenByAnotherAuthorization():void {
  $key=$this->key();self::assertTrue($this->reserve($key,str_repeat('a',64)));self::assertFalse($this->reserve($key,str_repeat('b',64)));global $wpdb;
  self::assertSame(str_repeat('a',64),$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)));
 }
 public function testUnknownNamespaceAndMalformedHashNeverReachSql():void {
  $queries=0;$hook=static function(string $q)use(&$queries):string{++$queries;return $q;};add_filter('query',$hook);
  try{self::assertFalse($this->reserve('stop_all',str_repeat('a',64)));self::assertFalse($this->reserve($this->key(),'malformed'));self::assertFalse($this->reserve('digiforge_pp_rate_3_123_10',str_repeat('a',64)));}finally{remove_filter('query',$hook);}
  self::assertSame(0,$queries);
 }
 public function testLostAcknowledgementRetainsConsumedClaim():void {
  $key=$this->key();$committed=false;global $wpdb;$prior=$wpdb->suppress_errors(true);
  $hook=static function(string $q)use($key,&$committed):string{if(!$committed&&str_starts_with($q,'INSERT IGNORE')&&str_contains($q,$key)){$committed=true;global $wpdb;$wpdb->query($q);return 'SELECT * FROM digiforge_synthetic_missing_claim_ack';}return $q;};
  add_filter('query',$hook);try{self::assertFalse($this->reserve($key,str_repeat('a',64)));}finally{remove_filter('query',$hook);$wpdb->suppress_errors($prior);}
  self::assertTrue($committed);self::assertSame(str_repeat('a',64),$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key)));self::assertFalse($this->reserve($key,str_repeat('b',64)));
 }
 public static function insufficientIndexes():array { return [[''],[', UNIQUE KEY option_name_prefix (option_name(20))'],[', UNIQUE KEY option_name_composite (option_name,option_id)']]; }
 /** @dataProvider insufficientIndexes */
 public function testNoUniqueOptionNameIndexFailsClosedWithoutInsertion(string $extraIndex):void {
  global $wpdb;$original=$wpdb->options;$table=$wpdb->prefix.'synthetic_claim_no_unique';$wpdb->query("CREATE TEMPORARY TABLE $table (option_id BIGINT PRIMARY KEY,option_name VARCHAR(191),option_value LONGTEXT,autoload VARCHAR(20)$extraIndex) ENGINE=InnoDB");$wpdb->options=$table;
  try{self::assertFalse($this->reserve($this->key(),str_repeat('a',64)));self::assertSame('0',$wpdb->get_var("SELECT COUNT(*) FROM $table"));}finally{$wpdb->options=$original;$wpdb->query("DROP TEMPORARY TABLE $table");}
 }
 public function testRateSlotHasOnlyOneWinner():void {
  $key='digiforge_pp_rate_'.$this->fixture->integration.'_'.intdiv(time(),60).'_0';
  try{self::assertTrue($this->reserve($key,str_repeat('a',64)));self::assertFalse($this->reserve($key,str_repeat('b',64)));self::assertSame(str_repeat('a',64),get_option($key));}finally{delete_option($key);}
 }

}
