<?php
declare(strict_types=1);
use DigiForgePrintifyConnector\Preparation\ArtworkContract;
use DigiForgePrintifyConnector\Preparation\UploadContract;
use PHPUnit\Framework\TestCase;
final class PrintifyCompanionArtworkUploadTest extends TestCase {
 private function fixture():array { $f=require __DIR__.'/../fixtures/printify-connector/stage34/artwork-fixture.php';return $f(); }
 public function testContractsExist():void { $this->fixture();$this->assertTrue(class_exists(ArtworkContract::class));$this->assertTrue(class_exists(UploadContract::class)); }
 public function testInspectAndPrepareAndReconcile():void {
  [$b,$e,$s]=$this->fixture();$a=ArtworkContract::inspect($b,$e,$s);$this->assertTrue($a['host_evidence_verification_required']);$this->assertArrayNotHasKey('contents',$a);$r=UploadContract::prepare($s,$a,$b);$this->assertFalse($r['network_request_permitted']);$this->assertSame($b,base64_decode($r['body']['contents'],true));$this->assertSame($r,UploadContract::prepare($s,$a,$b));
  $o=UploadContract::reconcile($r,['id'=>'abc_1','file_name'=>$r['body']['file_name'],'width'=>10,'height'=>10,'preview_url'=>'private']);$this->assertSame('METADATA_OBSERVED_CONTENT_UNVERIFIED',$o['state']);$this->assertArrayNotHasKey('preview_url',$o);$this->assertSame('UNKNOWN',UploadContract::reconcile($r,[],503)['state']);
 }
 /** @dataProvider invalidEvidence */
 public function testRejectsInvalidEvidence(string $path,mixed $value):void { [$b,$e,$s]=$this->fixture();$keys=explode('.',$path);$ref=&$e;foreach($keys as $k)$ref=&$ref[$k];$ref=$value;$this->expectException(InvalidArgumentException::class);ArtworkContract::inspect($b,$e,$s); }
 public static function invalidEvidence():array {return array_map(static fn($v)=>$v,[['shop_key','standard_pod'],['integration_id',8],['provider_shop_id','124'],['rights_receipt_sha256',true],['ownership_receipt_sha256',''],['geometry_receipt_sha256','wrong'],['asset_sha256',str_repeat('0',64)],['extra',true],['geometry.width_px',11],['geometry.height_px',11],['geometry.dpi',72],['geometry.width_px',10.0],['geometry.bleed_px',-1],['geometry.bleed_px',5],['geometry.safe_zone.x',0],['geometry.safe_zone.y',0],['geometry.safe_zone.width',9],['geometry.safe_zone.height',9],['geometry.safe_zone.width',0],['content_bounds.x',1],['content_bounds.y',1],['content_bounds.width',7],['content_bounds.height',7],['content_bounds.width',0],['content_bounds.x',INF],['geometry.extra',1],['content_bounds.extra',1]]); }
 /** @dataProvider invalidBytes */
 public function testRejectsMalformedBytes(string $mode):void {[$b,$e,$s,$c]=$this->fixture();$b=match($mode){'crc'=>substr_replace($b,'x',30,1),'oversize'=>str_repeat('x',2097153),'jpeg'=>"\xff\xd8jpeg",'truncated'=>substr($b,0,-1),'trailing'=>$b.'x','decode_limit'=>"\x89PNG\r\n\x1a\n".$c('IHDR',pack('NNCCCCC',10000,10000,8,6,0,0,0)).$c('pHYs',pack('NNC',11811,11811,1)).$c('IDAT',gzcompress('x')).$c('IEND','')};$e['asset_sha256']=hash('sha256',$b);if($mode==='decode_limit'){$e['geometry']['width_px']=10000;$e['geometry']['height_px']=10000;}$this->expectException(InvalidArgumentException::class);ArtworkContract::inspect($b,$e,$s);}
 public static function invalidBytes():array{return array_map(static fn($x)=>[$x],['crc','oversize','jpeg','truncated','trailing','decode_limit']);}
 /** @dataProvider invalidResponse */
 public function testRejectsMalformedResponse(string $key,mixed $value):void {[$b,$e,$s]=$this->fixture();$r=UploadContract::prepare($s,ArtworkContract::inspect($b,$e,$s),$b);$response=['id'=>'abc','file_name'=>$r['body']['file_name'],'width'=>10,'height'=>10];$response[$key]=$value;$this->expectException(InvalidArgumentException::class);UploadContract::reconcile($r,$response);}
 public static function invalidResponse():array{return [['id','https://bad'],['id',''],['file_name','bad.png'],['width',11],['height',11],['width','10']];}
 public function testRejectsTamperedAsset():void {[$b,$e,$s]=$this->fixture();$a=ArtworkContract::inspect($b,$e,$s);$a['width_px']=11;$this->expectException(InvalidArgumentException::class);UploadContract::prepare($s,$a,$b);}
 public function testMissingRightsReceiptRejected():void {[$b,$e,$s]=$this->fixture();unset($e['rights_receipt_sha256']);$this->expectException(InvalidArgumentException::class);ArtworkContract::inspect($b,$e,$s);}
 public function testRebindingSameBytesRejected():void {[$b,$e,$s]=$this->fixture();$a=ArtworkContract::inspect($b,$e,$s);$other=new \DigiForge\Connector\Printify\ShopBinding(3,'standard_pod',8,'124','test',1,new \DigiForge\Connector\Printify\CredentialReference(8),str_repeat('b',64),2000000000);$this->expectException(InvalidArgumentException::class);UploadContract::prepare($other,$a,$b);}
 public function testExtraAssetKeysRejected():void {[$b,$e,$s]=$this->fixture();$a=ArtworkContract::inspect($b,$e,$s);$a['url']='https://example.test';$this->expectException(InvalidArgumentException::class);UploadContract::prepare($s,$a,$b);}
 public function testTamperedRequestBodyRejected():void {[$b,$e,$s]=$this->fixture();$r=UploadContract::prepare($s,ArtworkContract::inspect($b,$e,$s),$b);$r['body']['contents']=base64_encode('tampered');$this->expectException(InvalidArgumentException::class);UploadContract::reconcile($r,[],503);}
 public function testInvalidCompressedPngRejected():void {[$b,$e,$s,$c]=$this->fixture();$b="\x89PNG\r\n\x1a\n".$c('IHDR',pack('NNCCCCC',10,10,8,6,0,0,0)).$c('pHYs',pack('NNC',11811,11811,1)).$c('IDAT','not zlib').$c('IEND','');$e['asset_sha256']=hash('sha256',$b);$this->expectException(InvalidArgumentException::class);ArtworkContract::inspect($b,$e,$s);}

}
