<?php
declare(strict_types=1);
require_once __DIR__.'/../support/NativePrintifyContractFixture.php';
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/NativeApprovedPackageFixture.php';
use DigiForge\POD\{PrintifyProductPipeline,PrintifyLifecycleWorkflow,PrintifyControlledTransport,ScopedProviderConnection,ProductPackageRepository};
final class NativeOwnedUploadGetTest extends WP_UnitTestCase {
 use NativeApprovedPackageFixture;
 private PrintifyApiApprovalFixture $fixture;
 public static function set_up_before_class():void{parent::set_up_before_class();PrintifyApiApprovalFixture::checked(DigiForge\Database\ProviderConnectionSchema::migrateIfNeeded());}
 protected function setUp():void{parent::setUp();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();}
 protected function tearDown():void{try{$this->fixture->restore();}finally{parent::tearDown();}}
 private function bind():void{$c=new ScopedProviderConnection();$p=PrintifyApiApprovalFixture::checked($c->propose('personalized_pod',['integration_id'=>$this->fixture->integration,'capability'=>'printify.product.prepare','source_sha256'=>hash('sha256','owned-upload-fixture'),'evidence_reference'=>'local://isolated/upload','observed_at'=>gmdate('c',time()-1)],time(),time()+1200,wp_generate_uuid4(),0));PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked($c->decide('personalized_pod',$this->fixture->integration,'printify.product.prepare',$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4()));$this->fixture->owner();}
 public function testNativeOwnedImageDetailAndListRequireActualSourceReceipts():void {
  $this->bind();$package=$this->approvedNativePackage();$v=PrintifyApiApprovalFixture::checked((new ProductPackageRepository())->read('personalized_pod',$package['package']));$design=$v['record']['commercial_design_metadata']['design_hash'];$d=PrintifyApiApprovalFixture::checked((new DigiForge\Research\DesignFactoryRepository())->read('personalized_pod',$design));$ref=$d['record']['asset']['raster_references'][0]['reference'];$raster=PrintifyApiApprovalFixture::checked((new DigiForge\Research\DesignFactoryRepository())->raster('personalized_pod',$design,$ref));
  $p=new PrintifyProductPipeline();$plan=PrintifyApiApprovalFixture::checked($p->prepare($package['workflow'],$package['package'],$this->fixture->integration,'IMAGE_UPLOAD',['asset_reference'=>$ref]));$auth=$this->fixture->approve($p,$plan,'printify-product');$bytes=base64_decode($raster['base64'],true);$image=['id'=>'owned_image','width'=>2475,'height'=>1155,'size'=>strlen($bytes),'mime_type'=>'image/png','file_name'=>'fixture.png','upload_time'=>'2026-11-09 00:00:00'];$uploaded=PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response($image)))->executeProduct($plan['hash'],$auth['hash']));self::assertSame('SUCCEEDED',$uploaded['record']['status']);
  $w=new DigiForge\POD\PrintifyApiReadWorkflow();
  foreach(['UPLOADS_READ','UPLOAD_READ']as $op){
   $input=$op==='UPLOAD_READ'?['upload_id'=>'owned_image']:[];
   $missing=new DigiForge\Connector\Printify\ExternalOperationIntent(1,'personalized_pod',$this->fixture->integration,$op,$input,bin2hex(random_bytes(16)));self::assertWPError($w->prepareNative($missing));
   $i=new DigiForge\Connector\Printify\ExternalOperationIntent(1,'personalized_pod',$this->fixture->integration,$op,$input,bin2hex(random_bytes(16)),[$uploaded['hash']]);
   $plan=PrintifyApiApprovalFixture::checked($w->prepareNative($i));$auth=$this->fixture->approve($w,$plan,'printify-api-read');self::assertNotWPError($w->bindNative($i,$plan['hash'],$auth['hash']));
   $foreign=$image;$foreign['id']='other_shop_image';$calls=0;$fake=static function()use(&$calls,$image,$foreign,$op){++$calls;return PrintifyApiApprovalFixture::response($op==='UPLOAD_READ'?$image:['current_page'=>1,'per_page'=>10,'last_page'=>1,'data'=>[$image,$foreign]]);};add_filter('pre_http_request',$fake,100);
   try{$reader=$this->nativeReader();$result=$reader->read($i);self::assertInstanceOf(DigiForge\Connector\Printify\ProviderResult::class,$result);self::assertCount(1,$result->data['items']);self::assertSame('owned_image',$result->data['items'][0]['id']);self::assertStringNotContainsString('other_shop_image',json_encode($result->data));self::assertWPError($reader->read($i));self::assertSame(1,$calls);}finally{remove_filter('pre_http_request',$fake,100);}
  }
  foreach(['wrong_identity','malformed_dimensions','foreign_shop','ownership_lost']as $fault){
   $i=new DigiForge\Connector\Printify\ExternalOperationIntent(1,'personalized_pod',$this->fixture->integration,'UPLOAD_READ',['upload_id'=>'owned_image'],bin2hex(random_bytes(16)),[$uploaded['hash']]);$plan=PrintifyApiApprovalFixture::checked($w->prepareNative($i));$auth=$this->fixture->approve($w,$plan,'printify-api-read');self::assertNotWPError($w->bindNative($i,$plan['hash'],$auth['hash']));$calls=0;
   $fake=static function()use(&$calls,$fault,$image,$uploaded){++$calls;$body=$image;if($fault==='wrong_identity')$body['id']='foreign_image';elseif($fault==='malformed_dimensions')$body['width']=0;elseif($fault==='foreign_shop')$body['shop_id']=999;else delete_option('digiforge_pp_receipt_'.$uploaded['hash']);return PrintifyApiApprovalFixture::response($body);};add_filter('pre_http_request',$fake,100);
   try{$r=(new DigiForge\Connector\Printify\NativeReadConnector())->read($i);self::assertWPError($r);self::assertSame('UNKNOWN',$r->get_error_data()['outcome']);self::assertTrue($r->get_error_data()['network_request_attempted']);self::assertFalse($r->get_error_data()['retry_permitted']);self::assertWPError((new DigiForge\Connector\Printify\NativeReadConnector())->read($i));self::assertSame(1,$calls);}finally{remove_filter('pre_http_request',$fake,100);}
  }
 }
 private function nativeReader():object {if(!is_file(DIGIFORGE_PATH.'includes/Connector/Printify/native-build.json'))return new DigiForge\Connector\Printify\NativeReadConnector();$port=DigiForge\Connector\Printify\NativeBridge::connect('1.0.0');$manifest=$port->contractManifest();self::assertContains('GET_READ',$manifest['capabilities']);return new DigiForgePrintifyConnector\Preparation\NativePortAdapter($port,$manifest['core_source_sha']);}

}
