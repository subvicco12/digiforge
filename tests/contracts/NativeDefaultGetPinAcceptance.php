<?php
declare(strict_types=1);
use DigiForge\Connector\Printify\{NativeBridge,ExternalOperationIntent,ProviderResult};
use DigiForgePrintifyConnector\Preparation\NativePortAdapter;
require_once DIGIFORGE_PATH.'tests/wordpress/fixtures/PrintifyApiApprovalFixture.php';
final class NativeDefaultGetPinAcceptance extends WP_UnitTestCase {
 public function testReviewedDefaultDiscoveryAdmitsCurrentCoreAndRejectsStalePin():void {
  $f=new PrintifyApiApprovalFixture();$f->boot();$fake=null;
  try{$w=new DigiForge\POD\PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$f->integration,'SHOPS_READ',[]));$a=$f->approve($w,$p,'printify-api-read');$i=new ExternalOperationIntent(1,'personalized_pod',$f->integration,'SHOPS_READ',[],bin2hex(random_bytes(16)));self::assertNotWPError($w->bindNative($i,$p['hash'],$a['hash']));$calls=0;$fake=static function()use(&$calls){++$calls;return PrintifyApiApprovalFixture::response([['id'=>12,'title'=>'Synthetic','sales_channel'=>'etsy']]);};add_filter('pre_http_request',$fake,100);
   foreach(['b367652c8d1ae1448cf4da286a8959fe32204b25','862caef4c69552f501efc1d959a1de92d8b88ca9','78e7f973455a3c89319be06f0907caf2103658a7','f525accb557c2516781abbe8dafb4095fa805935','7d624512a0fab416278b7d8f88208c9463224b6f','702a4d078555ac9cb75fd115357db5387281c74a','509abc0ea3b6e6a6ad855dcea24228e45bce4e5e','05091b954af8164fe7c447aa0e642e28be33630a']as $stalePin){self::assertWPError((new NativePortAdapter(NativeBridge::connect('1.0.0'),$stalePin))->read($i));self::assertSame(0,$calls);}
   self::assertInstanceOf(ProviderResult::class,DigiForgePrintifyConnector\Plugin::nativePort()->read($i));self::assertSame('d64d32159bea4832b962acb6b4c08fa6fbeae910',DigiForgePrintifyConnector\Plugin::nativePort()->contractManifest()['core_source_sha']);self::assertSame(1,$calls);self::assertWPError(DigiForgePrintifyConnector\Plugin::nativePort()->read($i));self::assertSame(1,$calls);
  }finally{if($fake!==null)remove_filter('pre_http_request',$fake,100);$f->restore();}
 }
}
