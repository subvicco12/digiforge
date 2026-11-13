<?php
declare(strict_types=1);
require_once __DIR__.'/../support/NativePrintifyContractFixture.php';
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
use DigiForge\POD\{PrintifyApiReadWorkflow,PrintifyCatalogReadRepository,ScopedProviderConnection};
use DigiForge\Connector\Printify\{ExternalOperationIntent,NativeReadConnector,ProviderResult};
final class NativeSupplementalGetTest extends WP_UnitTestCase {
 private PrintifyApiApprovalFixture $fixture;
 protected function setUp():void{parent::setUp();if(!defined('DIGIFORGE_MAIN_SITE_URL'))define('DIGIFORGE_MAIN_SITE_URL',site_url());$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();}
 protected function tearDown():void{$this->fixture->restore();parent::tearDown();}
 public static function catalog():array{return [
  ['BLUEPRINTS_READ',[],['kind'=>'blueprints'],[['id'=>101,'title'=>'Synthetic blueprint']]],
  ['BLUEPRINT_READ',['blueprint_id'=>101],['kind'=>'blueprint','blueprint_id'=>101],['id'=>101,'title'=>'Synthetic blueprint']],
  ['VARIANTS_READ',['blueprint_id'=>101,'provider_id'=>7],['kind'=>'variants','blueprint_id'=>101,'provider_id'=>7],['variants'=>[['id'=>3,'title'=>'Synthetic variant','placeholders'=>[['position'=>'front','width'=>100,'height'=>200]]]]]],
  ['SHIPPING_READ',['blueprint_id'=>101,'provider_id'=>7],['kind'=>'shipping','blueprint_id'=>101,'provider_id'=>7],['data'=>[['id'=>'1','type'=>'shipping_method','attributes'=>['name'=>'standard']]]]],
 ];}
 private function catalogGrant(array $request):string {
  $c=new ScopedProviderConnection();$p=PrintifyApiApprovalFixture::checked($c->propose('personalized_pod',['integration_id'=>$this->fixture->integration,'capability'=>'printify.catalog.read','source_sha256'=>hash('sha256',wp_generate_uuid4()),'evidence_reference'=>'local://isolated/native-catalog','observed_at'=>gmdate('c',time()-1)],time(),time()+1200,wp_generate_uuid4(),0));PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked($c->decide('personalized_pod',$this->fixture->integration,'printify.catalog.read',$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4()));$this->fixture->owner();
  $i=(new DigiForge\Integrations\Repository())->find($this->fixture->integration);$g=(new PrintifyCatalogReadRepository())->approve(['capability'=>'printify.catalog.read','integration_id'=>$this->fixture->integration,'account_id'=>1]+$i['config']['catalog_read_scope']+['expires_at'=>gmdate('c',time()+600),'max_requests'=>1,'requests'=>[$request]],wp_generate_uuid4());return PrintifyApiApprovalFixture::checked($g)['sha256'];
 }
 /** @dataProvider catalog */
 public function testNativeCatalogUsesRealScopedGrantAndOneUse(string $op,array $input,array $request,array $body):void {
  self::assertTrue(method_exists(PrintifyApiReadWorkflow::class,'prepareNative'),'Native supplemental source join must exist');
  $grant=$this->catalogGrant($request);$i=new ExternalOperationIntent(1,'personalized_pod',$this->fixture->integration,$op,$input,bin2hex(random_bytes(16)));$w=new PrintifyApiReadWorkflow();self::assertWPError($w->prepareNative($i));$p=PrintifyApiApprovalFixture::checked($w->prepareNative($i,$grant,0));$a=$this->fixture->approve($w,$p,'printify-api-read');self::assertNotWPError($w->bindNative($i,$p['hash'],$a['hash']));$calls=0;$fake=static function()use(&$calls,$body){++$calls;return PrintifyApiApprovalFixture::response($body);};add_filter('pre_http_request',$fake,100);try{$reader=$this->nativeReader();$r=$reader->read($i);self::assertInstanceOf(ProviderResult::class,$r);self::assertSame($op,$r->receipt['operation']);self::assertWPError($reader->read($i));self::assertSame(1,$calls);}finally{remove_filter('pre_http_request',$fake,100);}
 }
 public function testLegacySingleVariantGrantCannotAuthorizeWholeVariantList():void {
  $grant=$this->catalogGrant(['kind'=>'variant','blueprint_id'=>101,'provider_id'=>7,'variant_id'=>3]);$i=new ExternalOperationIntent(1,'personalized_pod',$this->fixture->integration,'VARIANTS_READ',['blueprint_id'=>101,'provider_id'=>7],bin2hex(random_bytes(16)));self::assertWPError((new PrintifyApiReadWorkflow())->prepareNative($i,$grant,0));
 }
 public function testCatalogRevocationDuringResponsePreservesUnknownAndConsumesClaim():void {
  $grant=$this->catalogGrant(['kind'=>'blueprints']);$i=new ExternalOperationIntent(1,'personalized_pod',$this->fixture->integration,'BLUEPRINTS_READ',[],bin2hex(random_bytes(16)));$w=new PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepareNative($i,$grant));$a=$this->fixture->approve($w,$p,'printify-api-read');self::assertNotWPError($w->bindNative($i,$p['hash'],$a['hash']));$calls=0;$fake=static function()use(&$calls,$grant){++$calls;PrintifyApiApprovalFixture::checked((new PrintifyCatalogReadRepository())->revoke($grant));return PrintifyApiApprovalFixture::response([['id'=>101]]);};add_filter('pre_http_request',$fake,100);try{$reader=$this->nativeReader();$r=$reader->read($i);self::assertWPError($r);if($reader instanceof NativeReadConnector){self::assertSame('UNKNOWN',$r->get_error_data()['outcome']);self::assertFalse($r->get_error_data()['retry_permitted']);}self::assertWPError($reader->read($i));self::assertSame(1,$calls);}finally{remove_filter('pre_http_request',$fake,100);}
 }
 public function testIndependentNativePlansShareExistingCatalogQuota():void {
  $grant=$this->catalogGrant(['kind'=>'blueprints']);$w=new PrintifyApiReadWorkflow();$reads=[];foreach([1,2]as $n){$i=new ExternalOperationIntent(1,'personalized_pod',$this->fixture->integration,'BLUEPRINTS_READ',[],bin2hex(random_bytes(16)));$p=PrintifyApiApprovalFixture::checked($w->prepareNative($i,$grant));$a=$this->fixture->approve($w,$p,'printify-api-read');self::assertNotWPError($w->bindNative($i,$p['hash'],$a['hash']));$reads[]=$i;}$calls=0;$fake=static function()use(&$calls){++$calls;return PrintifyApiApprovalFixture::response([['id'=>101]]);};add_filter('pre_http_request',$fake,100);try{$reader=$this->nativeReader();self::assertInstanceOf(ProviderResult::class,$reader->read($reads[0]));self::assertWPError($reader->read($reads[1]));self::assertSame(1,$calls);}finally{remove_filter('pre_http_request',$fake,100);}
 }
 public static function rejectedResponses():array{return [['malformed',200],['rate',429]];}
 /** @dataProvider rejectedResponses */
 public function testRejectedCatalogResponseCannotBeReplayed(string $fault,int $status):void {
  $grant=$this->catalogGrant(['kind'=>'blueprints']);$i=new ExternalOperationIntent(1,'personalized_pod',$this->fixture->integration,'BLUEPRINTS_READ',[],bin2hex(random_bytes(16)));$w=new PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepareNative($i,$grant));$a=$this->fixture->approve($w,$p,'printify-api-read');self::assertNotWPError($w->bindNative($i,$p['hash'],$a['hash']));$calls=0;$fake=static function()use(&$calls,$status){++$calls;$r=PrintifyApiApprovalFixture::response(['invalid'=>'shape'],$status);if($status===429)$r['headers']=['retry-after'=>'30'];return $r;};add_filter('pre_http_request',$fake,100);try{$r=(new NativeReadConnector())->read($i);self::assertWPError($r);self::assertSame('UNKNOWN',$r->get_error_data()['outcome']);self::assertFalse($r->get_error_data()['retry_permitted']);self::assertWPError((new NativeReadConnector())->read($i));self::assertSame(1,$calls);}finally{remove_filter('pre_http_request',$fake,100);}
 }
 private function nativeReader():object {if(!is_file(DIGIFORGE_PATH.'includes/Connector/Printify/native-build.json'))return new NativeReadConnector();$port=DigiForge\Connector\Printify\NativeBridge::connect('1.0.0');$manifest=$port->contractManifest();self::assertContains('GET_READ',$manifest['capabilities']);return new DigiForgePrintifyConnector\Preparation\NativePortAdapter($port,$manifest['core_source_sha']);}

}
