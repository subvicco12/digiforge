<?php
declare(strict_types=1);
use DigiForge\Connector\Printify\ExternalOperationIntent;
require_once DIGIFORGE_PATH.'tests/wordpress/fixtures/PrintifyApiApprovalFixture.php';
/** Explicit fault injection into a disposable extracted artifact only; never a normal source profile. */
final class NativeArtifactIntegrityAcceptance extends WP_UnitTestCase {
 public static function alteredArtifacts():array{return array_map(static fn(string $fault):array=>[$fault],['wrong_source','wrong_schema','changed_member','wrong_contract','extra_runtime']);}
 /** @dataProvider alteredArtifacts */
 public function testDefaultDiscoveryRejectsAlteredDisposableArtifactBeforeTransport(string $fault):void {
  $root=realpath(DIGIFORGE_PATH);$allowed=realpath((string)getenv('DF_NATIVE_MUTABLE_FIXTURE'));
  self::assertNotFalse($root);self::assertSame($root,$allowed,'Fault tests require an explicitly selected disposable artifact, never a source checkout');self::assertFileExists($root.'/.digiforge-disposable-native-fixture');self::assertFileDoesNotExist($root.'/.git');self::assertDirectoryDoesNotExist($root.'/.git');
  $native=$root.'/includes/Connector/Printify/';$path=$native.'native-build.json';$before=file_get_contents($path);self::assertIsString($before);$changed=$before;$added=null;
  if(in_array($fault,['wrong_source','wrong_schema'],true)){$stamp=json_decode($before,true,8,JSON_THROW_ON_ERROR);$stamp[$fault==='wrong_source'?'source_sha':'schema']=$fault==='wrong_source'?'862caef4c69552f501efc1d959a1de92d8b88ca9':'DF_NATIVE_BUILD_999';$changed=json_encode($stamp,JSON_THROW_ON_ERROR);}
  elseif($fault==='extra_runtime'){$added=$native.'NativeUnexpectedRuntime.php';self::assertFileDoesNotExist($added);}
  else{$path=$native.($fault==='changed_member'?'NativeReadConnector.php':'native-core-port.v1.json');$before=file_get_contents($path);self::assertIsString($before);$changed=$before."\n";}
  $f=new PrintifyApiApprovalFixture();$f->boot();$calls=0;$fake=static function()use(&$calls){++$calls;return PrintifyApiApprovalFixture::response([]);};add_filter('pre_http_request',$fake,100);
  try{
   $i=new ExternalOperationIntent(1,'personalized_pod',$f->integration,'SHOPS_READ',[],bin2hex(random_bytes(16)));
   self::assertSame('2c9fcca6e14fbf64f94414661d42d85715039070',DigiForgePrintifyConnector\Plugin::nativePort()->contractManifest()['core_source_sha']);
   if($added!==null)self::assertNotFalse(file_put_contents($added,"<?php\n// Synthetic unexpected runtime inventory member.\n"));else self::assertNotFalse(file_put_contents($path,$changed));
   $manifestDenied=false;try{DigiForgePrintifyConnector\Plugin::nativePort()->contractManifest();}catch(RuntimeException){$manifestDenied=true;}self::assertTrue($manifestDenied,'Altered identity, contract or runtime inventory must deny manifest admission itself');
   self::assertWPError(DigiForgePrintifyConnector\Plugin::nativePort()->read($i));self::assertSame(0,$calls);
  }finally{if($added!==null){if(is_file($added))unlink($added);}else file_put_contents($path,$before);remove_filter('pre_http_request',$fake,100);$f->restore();}
  self::assertSame('2c9fcca6e14fbf64f94414661d42d85715039070',DigiForgePrintifyConnector\Plugin::nativePort()->contractManifest()['core_source_sha']);
 }
}
