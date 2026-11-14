<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
if(!function_exists('wp_json_encode')){function wp_json_encode(mixed $value,int $flags=0):string|false{return json_encode($value,$flags);}}
use DigiForge\Connector\Printify\{ReadProjection,ExternalOperationIntent,ShopBinding,CredentialReference};
final class NativeProjectionCompatibilityTest extends TestCase {
 public function testNativeProjectionPreservesAllTwentyCoreValidatedPlacementImages():void {
  $images=[];for($n=1;$n<=20;$n++)$images[]=['id'=>'synthetic_'.$n,'x'=>0.5,'y'=>0.5,'scale'=>1,'angle'=>0];
  $input=['product_id'=>'synthetic_product','blueprint_id'=>1,'provider_id'=>7,'variant_ids'=>[3]];
  $body=['id'=>'synthetic_product','shop_id'=>'12','blueprint_id'=>1,'print_provider_id'=>7,'variants'=>[['id'=>3,'price'=>1000,'is_enabled'=>true]],'print_areas'=>[['variant_ids'=>[3],'placeholders'=>[['position'=>'front','images'=>$images]]]],'images'=>[]];
  $validated=DigiForge\POD\PrintifyApiReadEvidence::project('PRODUCT_READ',$body,'12',$input);self::assertCount(20,$validated['print_areas'][0]['placeholders'][0]['images']);
  $scope=new ShopBinding(2,'personalized_pod',3,'12','test',1,new CredentialReference(3),str_repeat('a',64),time()+60);
  $intent=new ExternalOperationIntent(1,'personalized_pod',3,'PRODUCT_READ',['product_id'=>'synthetic_product'],str_repeat('a',32));
  $r=ReadProjection::project($intent,$scope,$validated);self::assertCount(20,$r['items'][0]['print_areas'][0]['placeholders'][0]['images']);
 }
 public function testTwentyOnePlacementImagesRemainRejected():void {
  $scope=new ShopBinding(2,'personalized_pod',3,'12','test',1,new CredentialReference(3),str_repeat('a',64),time()+60);$intent=new ExternalOperationIntent(1,'personalized_pod',3,'PRODUCT_READ',['product_id'=>'p'],str_repeat('a',32));
  $row=['id'=>'p','print_areas'=>[['variant_ids'=>[3],'placeholders'=>[['position'=>'front','images'=>array_fill(0,21,['id'=>'i','x'=>0.5,'y'=>0.5,'scale'=>1,'angle'=>0])]]]]];
  $this->expectException(InvalidArgumentException::class);ReadProjection::project($intent,$scope,$row);
 }
 public static function orderOperations():array{return [['ORDER_READ'],['ORDERS_READ']];}
 /** @dataProvider orderOperations */
 public function testContradictoryOrderShopRejectedBeforeProjection(string $op):void {
  $order=['id'=>'synthetic_order','shop_id'=>999,'status'=>'pending','line_items'=>[]];$data=$op==='ORDER_READ'?$order:['data'=>[$order],'current_page'=>1,'per_page'=>10,'last_page'=>1];
  $this->expectException(InvalidArgumentException::class);DigiForge\POD\PrintifyApiReadEvidence::project($op,$data,'12',$op==='ORDER_READ'?['order_id'=>'synthetic_order']:[]);
 }
 public function testContradictoryOwnedUploadShopRejectedBeforeProjection():void {
  $request=DigiForge\POD\PrintifyUploadContract::prepare('UPLOADS_READ','12',[])+['owned_images'=>[['image_id'=>'owned','receipt_hash'=>str_repeat('a',64),'plan_hash'=>str_repeat('b',64)]]];
  $body=['data'=>[['id'=>'owned','shop_id'=>999,'width'=>100,'height'=>100,'size'=>100,'file_name'=>'synthetic.png','mime_type'=>'image/png','upload_time'=>'2026-11-13 00:00:00']],'current_page'=>1,'per_page'=>10,'last_page'=>1];
  $this->expectException(InvalidArgumentException::class);DigiForge\POD\PrintifyUploadContract::project($request,$body);
 }

 public function testCoreOnlyDefaultDiscoveryHasItsPinnedDependencyAndStillDeniesUnreviewedSource():void {
  $adapter=DigiForgePrintifyConnector\Preparation\NativePortAdapter::discover();self::assertInstanceOf(DigiForgePrintifyConnector\Preparation\NativeCorePortInterface::class,$adapter);
  self::assertSame('702a4d078555ac9cb75fd115357db5387281c74a',DigiForgePrintifyConnector\Preparation\CoreContractAdapter::REFERENCE_SHA);
  $i=new ExternalOperationIntent(1,'personalized_pod',3,'SHOPS_READ',[],str_repeat('a',32));self::assertInstanceOf(WP_Error::class,$adapter->read($i));
 }

}
