<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/PrintifyWebhookConfigFixture.php';
use DigiForge\POD\PrintifyApiReadWorkflow;
use DigiForge\POD\PrintifyLifecycleWorkflow;
use DigiForge\POD\PrintifyControlledTransport;

final class PrintifyLifecycleExecutionTest extends WP_UnitTestCase
{
 private PrintifyApiApprovalFixture $fixture;
 protected function setUp():void {parent::setUp();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();}
 protected function tearDown():void {try{$this->fixture->restore();}finally{parent::tearDown();}}
 private function planRead(string $operation='SHOPS_READ',array $input=[]):array {return PrintifyApiApprovalFixture::checked((new PrintifyApiReadWorkflow())->prepare('personalized_pod',$this->fixture->integration,$operation,$input));}
 public function testReviewedReadExecutesOnlyOnceWithSafeProjection():void
 {
  $workflow=new PrintifyApiReadWorkflow();$plan=$this->planRead();$authorization=$this->fixture->approve($workflow,$plan,'printify-api-read');$calls=0;
  $transport=new PrintifyControlledTransport(static function($url,$args)use(&$calls){++$calls;self::assertSame('https://api.printify.com/v1/shops.json',$url);self::assertSame('GET',$args['method']);self::assertSame(0,$args['redirection']);return PrintifyApiApprovalFixture::response([['id'=>12,'title'=>'Bound shop','sales_channel'=>'custom'],['id'=>13,'title'=>'Other shop','sales_channel'=>'custom']]);});
  $result=$transport->executeApiRead($plan['hash'],$authorization['hash']);self::assertIsArray($result);self::assertSame('SUCCEEDED',$result['record']['status']);self::assertCount(1,$result['record']['data']['shops']);self::assertSame(12,$result['record']['data']['shops'][0]['id']);
  self::assertIsArray($workflow->receipt($result['hash']));self::assertWPError($transport->executeApiRead($plan['hash'],$authorization['hash']));self::assertSame(1,$calls);
 }
 public function testConnectionVerificationUsesTheExistingGovernedReadBoundary():void{
  $w=new PrintifyApiReadWorkflow();$p=$this->planRead();$a=$this->fixture->approve($w,$p,'printify-api-read');$calls=0;$fake=static function($pre,$args,$url)use(&$calls){if($url==='https://api.printify.com/v1/shops.json'){++$calls;return PrintifyApiApprovalFixture::response([['id'=>12,'title'=>'Bound','sales_channel'=>'custom']]);}return $pre;};add_filter('pre_http_request',$fake,10,3);try{$r=(new DigiForge\Integrations\ConnectionTester())->testPrintifyGoverned($this->fixture->integration,$p['hash'],$a['hash']);self::assertIsArray($r);self::assertTrue($r['ok']);self::assertFalse($r['configuration_changed']);self::assertSame(1,$calls);}finally{remove_filter('pre_http_request',$fake,10);}
 }
 public function testMappedExistingProductReadSurvivesCanonicalPlanStorage():void {
  $w=new PrintifyApiReadWorkflow();$p=$this->planRead('PRODUCT_READ',['product_id'=>'p1','blueprint_id'=>5,'provider_id'=>7,'variant_ids'=>[3]]);$a=$this->fixture->approve($w,$p,'printify-api-read');$t=new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response(['id'=>'p1','shop_id'=>12,'blueprint_id'=>5,'print_provider_id'=>7,'variants'=>[['id'=>3,'price'=>1000,'is_enabled'=>true]],'print_areas'=>[['variant_ids'=>[3],'placeholders'=>[['position'=>'front','images'=>[]]]]],'images'=>[]]));$r=PrintifyApiApprovalFixture::checked($t->executeApiRead($p['hash'],$a['hash']));self::assertSame('SUCCEEDED',$r['record']['status']);self::assertSame('p1',$r['record']['data']['id']);self::assertFalse($r['record']['data']['supplier_certified']);
 }
 public function testGetTransientFailureHasBoundedReviewedRetries():void
 {
  $workflow=new PrintifyApiReadWorkflow();$plan=$this->planRead();$payload=$workflow->approvalPayload($plan['hash']);self::assertSame(3,$payload['max_requests']);$auth=$this->fixture->approve($workflow,$plan,'printify-api-read');$calls=0;
  $t=new PrintifyControlledTransport(static function()use(&$calls){++$calls;return $calls===1?PrintifyApiApprovalFixture::response([],503):PrintifyApiApprovalFixture::response([['id'=>12,'title'=>'Bound','sales_channel'=>'custom']]);});
  $r=$t->executeApiRead($plan['hash'],$auth['hash']);self::assertIsArray($r);self::assertSame('SUCCEEDED',$r['record']['status']);self::assertSame(2,$calls);
 }
 public function testQuotePlanIsEncryptedAndDispatchIsExactlyApproved():void
 {
  $w=new PrintifyLifecycleWorkflow();$order=PrintifyApiApprovalFixture::order();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'SHIPPING_QUOTE',['order'=>$order]));
  $stored=get_option('digiforge_pfy_lifecycle_plan_'.$p['hash']);self::assertIsString($stored);self::assertStringNotContainsString('123 Fixture Street',$stored);self::assertStringNotContainsString('synthetic@example.invalid',$stored);self::assertSame(DigiForge\Listings\Validator::canonicalJson($order),DigiForge\Listings\Validator::canonicalJson($w->plan($p['hash'])['input']['order']));
  $a=$this->fixture->approve($w,$p,'printify-lifecycle');$calls=0;$t=new PrintifyControlledTransport(static function($url,$args)use(&$calls,$order){++$calls;self::assertSame('https://api.printify.com/v1/shops/12/orders/shipping.json',$url);self::assertSame('POST',$args['method']);self::assertSame(DigiForge\Listings\Validator::canonicalJson(['line_items'=>$order['line_items'],'address_to'=>$order['address_to']]),DigiForge\Listings\Validator::canonicalJson(json_decode($args['body'],true)));return PrintifyApiApprovalFixture::response(['standard'=>499,'priority'=>899]);});
  $r=$t->executeLifecycle($p['hash'],$a['hash']);self::assertIsArray($r);self::assertSame('SUCCEEDED',$r['record']['status']);self::assertSame(499,$r['record']['data']['rates_minor']['standard']);self::assertIsArray($w->receipt($r['hash']));self::assertWPError($t->executeLifecycle($p['hash'],$a['hash']));self::assertSame(1,$calls);
 }
 public function testUnknownCancellationNeverRetriesOrDuplicates():void
 {
  $read=new PrintifyApiReadWorkflow();$p=$this->planRead('ORDER_READ',['order_id'=>'o1']);$a=$this->fixture->approve($read,$p,'printify-api-read');$t=new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response(['id'=>'o1','status'=>'on-hold','line_items'=>[]]));$receipt=PrintifyApiApprovalFixture::checked($t->executeApiRead($p['hash'],$a['hash']));self::assertSame('SUCCEEDED',$receipt['record']['status']);
  $w=new PrintifyLifecycleWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'ORDER_CANCEL',['order_id'=>'o1'],$receipt['hash']));$a=$this->fixture->approve($w,$p,'printify-lifecycle');$calls=0;$t=new PrintifyControlledTransport(static function()use(&$calls){++$calls;return new WP_Error('synthetic_unknown','Synthetic transport interruption');});
  $r=$t->executeLifecycle($p['hash'],$a['hash']);self::assertIsArray($r);self::assertSame('UNKNOWN',$r['record']['status']);self::assertFalse($r['record']['retry_permitted']);self::assertWPError($t->executeLifecycle($p['hash'],$a['hash']));self::assertSame(1,$calls);
  self::assertWPError($w->reconcile($r['hash'],$receipt['hash']));
  $readPlan=$this->planRead('ORDER_READ',['order_id'=>'o1']);$readAuth=$this->fixture->approve($read,$readPlan,'printify-api-read');$readTransport=new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response(['id'=>'o1','status'=>'canceled','line_items'=>[]]));$newRead=PrintifyApiApprovalFixture::checked($readTransport->executeApiRead($readPlan['hash'],$readAuth['hash']));$reconciled=PrintifyApiApprovalFixture::checked($w->reconcile($r['hash'],$newRead['hash']));self::assertSame('CONFIRMED_BY_READBACK',$reconciled['record']['disposition']);self::assertFalse($reconciled['record']['retry_authorized']);self::assertWPError($t->executeLifecycle($p['hash'],$a['hash']));
 }
 public function testLockedExecutionAndMissingModuleNeverSend():void
 {
  $w=new PrintifyLifecycleWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'SHIPPING_QUOTE',['order'=>PrintifyApiApprovalFixture::order()]));$a=$this->fixture->approve($w,$p,'printify-lifecycle');$calls=0;$t=new PrintifyControlledTransport(static function()use(&$calls){++$calls;throw new RuntimeException('Unexpected dispatch');});
  $this->fixture->setting('stop_all',true);self::assertWPError($t->executeLifecycle($p['hash'],$a['hash']));$this->fixture->setting('stop_all',false);
  wp_get_current_user()->add_cap('manage_digiforge_orders',false);try{self::assertWPError($w->prepare('personalized_pod',$this->fixture->integration,'SHIPPING_QUOTE',['order'=>PrintifyApiApprovalFixture::order()]));self::assertWPError($t->executeLifecycle($p['hash'],$a['hash']));}finally{wp_get_current_user()->add_cap('manage_digiforge_orders',true);}
  self::assertSame(0,$calls);
 }
 public function testCrossShopAndUnreviewedPlansCannotExecute():void
 {
  $w=new PrintifyLifecycleWorkflow();self::assertWPError($w->prepare('standard_pod',$this->fixture->integration,'SHIPPING_QUOTE',['order'=>PrintifyApiApprovalFixture::order()]));
  $p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'SHIPPING_QUOTE',['order'=>PrintifyApiApprovalFixture::order()]));self::assertWPError($w->authorize($p['hash']));
  $read=new PrintifyApiReadWorkflow();$p=$this->planRead();self::assertWPError($read->authorize($p['hash']));self::assertWPError($read->prepare('standard_pod',$this->fixture->integration,'SHOPS_READ',[]));
 }
 public function testStandardPodHasIndependentConfigurationWithoutActivation():void {
  global $wpdb;$tables=DigiForge\Database\Tables::class;$repo=new DigiForge\Integrations\Repository();$existing=PrintifyApiApprovalFixture::checked($repo->find($this->fixture->integration));$cfg=$existing['config'];$now=current_time('mysql',true);
  $business=(int)$cfg['catalog_read_scope']['business_id'];$wpdb->insert($tables::stores(),['business_id'=>$business,'store_key'=>'standard-'.wp_generate_uuid4(),'display_name'=>'Synthetic standard store','channel'=>'etsy','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$store=(int)$wpdb->insert_id;$wpdb->insert($tables::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'ORIGINAL_DESIGN_POD','status'=>'ACTIVE','config'=>'{}','created_at'=>$now,'updated_at'=>$now]);
  $i=PrintifyApiApprovalFixture::checked($repo->create(['provider'=>'printify','environment'=>'test','connection_key'=>'standard-'.wp_generate_uuid4(),'display_name'=>'Synthetic Shop3','status'=>'CONFIGURED','enabled'=>false,'config'=>['shop_key'=>'standard_pod','shop_id'=>'13','account_reference'=>$cfg['account_reference'],'catalog_read_scope'=>['business_id'=>(string)$business,'store_id'=>(string)$store,'product_program'=>'ORIGINAL_DESIGN_POD']]]));PrintifyApiApprovalFixture::checked($repo->storeSecret($i['id'],'personal_access_token','synthetic-standard-material'));
  $connection=new DigiForge\POD\ScopedProviderConnection();$p=$connection->propose('standard_pod',['integration_id'=>$i['id'],'capability'=>'printify.connection.read','source_sha256'=>hash('sha256','standard-scope'),'evidence_reference'=>'local://isolated/standard','observed_at'=>gmdate('c',time()-1)],time(),time()+600,wp_generate_uuid4(),0);self::assertIsArray($p);self::assertSame('ORIGINAL_DESIGN_POD',$p['payload']['product_program']);self::assertSame('13',$p['payload']['external_shop_reference']);self::assertSame('DEFERRED_SHOP',$connection->status('standard_pod',$i['id'],'printify.connection.read')['status']);self::assertWPError(DigiForge\POD\PrintifyConnectionBoundary::context('standard_pod',$i['id'],'printify.connection.read'));
 }
 public function testGovernedWebhookCreateReadUpdateDeleteAndReconciliation():void {
  $path=PrintifyWebhookConfigFixture::path();$binding=str_repeat('d',32);$secret=str_repeat('s',40);file_put_contents($path,wp_json_encode([$binding=>['site'=>home_url(),'shop_key'=>'personalized_pod','integration_id'=>$this->fixture->integration,'provider_shop_id'=>'12','current_secret'=>$secret]]));chmod($path,0600);
  $https=static fn($url)=>set_url_scheme($url,'https');add_filter('rest_url',$https);
  try{
   $url=rest_url('digiforge/v1/printify/webhooks/ingress/'.$binding);$body=['id'=>'hook1','topic'=>'order:created','url'=>$url,'shop_id'=>'12'];$w=new PrintifyLifecycleWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'WEBHOOK_CREATE',['binding'=>$binding,'topic'=>'order:created']));$a=$this->fixture->approve($w,$p,'printify-lifecycle');
   $transport=new PrintifyControlledTransport(static function($target,$args)use($secret,$body){self::assertSame('POST',$args['method']);$json=json_decode($args['body'],true);self::assertSame($secret,$json['secret']);return PrintifyApiApprovalFixture::response($body);});$created=PrintifyApiApprovalFixture::checked($transport->executeLifecycle($p['hash'],$a['hash']));self::assertSame('SUCCEEDED',$created['record']['status']);self::assertStringNotContainsString($secret,wp_json_encode($created));
   $list=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'WEBHOOK_READ',[]));$auth=$this->fixture->approve($w,$list,'printify-lifecycle');$read=PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response([$body])))->executeLifecycle($list['hash'],$auth['hash']));$reconciled=PrintifyApiApprovalFixture::checked($w->reconcile($created['hash'],$read['hash']));self::assertSame('CONFIRMED_BY_READBACK',$reconciled['record']['disposition']);self::assertFalse($reconciled['record']['webhook_secret_live_verified']);
   $update=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'WEBHOOK_UPDATE',['binding'=>$binding,'topic'=>'order:created','webhook_id'=>'hook1']));$auth=$this->fixture->approve($w,$update,'printify-lifecycle');$updated=PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static function($target,$args)use($body){self::assertSame('PUT',$args['method']);self::assertSame(['url'],array_keys(json_decode($args['body'],true)));return PrintifyApiApprovalFixture::response($body);}))->executeLifecycle($update['hash'],$auth['hash']));self::assertSame('SUCCEEDED',$updated['record']['status']);
   $delete=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'WEBHOOK_DELETE',['binding'=>$binding,'webhook_id'=>'hook1','host'=>parse_url($url,PHP_URL_HOST)]));$auth=$this->fixture->approve($w,$delete,'printify-lifecycle');$deleted=PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static function($target,$args){self::assertSame('DELETE',$args['method']);self::assertStringContainsString('?host=',$target);return PrintifyApiApprovalFixture::response(['id'=>'hook1']);}))->executeLifecycle($delete['hash'],$auth['hash']));self::assertSame('SUCCEEDED',$deleted['record']['status']);
  }finally{remove_filter('rest_url',$https);}
 }
}
