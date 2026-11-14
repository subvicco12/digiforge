<?php
declare(strict_types=1);
use DigiForge\Connector\Printify\{NativeBridge,ExternalOperationIntent,ProviderResult};
use DigiForgePrintifyConnector\Preparation\NativePortAdapter;
require_once DIGIFORGE_PATH.'tests/wordpress/fixtures/PrintifyApiApprovalFixture.php';
final class NativeDefaultGetPinAcceptance extends WP_UnitTestCase {
 public function testReviewedDefaultDiscoveryAdmitsCurrentCoreAndRejectsStalePin():void {
  $f=new PrintifyApiApprovalFixture();$f->boot();$fake=null;
  try{$w=new DigiForge\POD\PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$f->integration,'SHOPS_READ',[]));$a=$f->approve($w,$p,'printify-api-read');$i=new ExternalOperationIntent(1,'personalized_pod',$f->integration,'SHOPS_READ',[],bin2hex(random_bytes(16)));self::assertNotWPError($w->bindNative($i,$p['hash'],$a['hash']));$calls=0;$fake=static function()use(&$calls){++$calls;return PrintifyApiApprovalFixture::response([['id'=>12,'title'=>'Synthetic','sales_channel'=>'etsy']]);};add_filter('pre_http_request',$fake,100);
   self::assertWPError((new NativePortAdapter(NativeBridge::connect('1.0.0'),'702a4d078555ac9cb75fd115357db5387281c74a'))->read($i));self::assertSame(0,$calls);
   self::assertInstanceOf(ProviderResult::class,DigiForgePrintifyConnector\Plugin::nativePort()->read($i));self::assertSame(1,$calls);self::assertWPError(DigiForgePrintifyConnector\Plugin::nativePort()->read($i));self::assertSame(1,$calls);
  }finally{if($fake!==null)remove_filter('pre_http_request',$fake,100);$f->restore();}
 }
}
