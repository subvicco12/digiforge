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
}
