<?php
namespace DigiForge\Tests;
use DigiForge\POD\PrintifyProductResponse;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/POD/PrintifyProductResponse.php';
final class PrintifyProductResponseTest extends TestCase {
 private function plan():array{return ['request'=>['operation'=>'PRODUCT_CREATE','endpoint'=>'/v1/shops/12/products.json','body'=>['variants'=>[['id'=>9,'price'=>2500,'is_enabled'=>true]],'print_areas'=>[['variant_ids'=>[9],'placeholders'=>[['position'=>'front','images'=>[['id'=>'image_1','x'=>0.5,'y'=>0.5,'scale'=>1,'angle'=>0]]]]]]]],'options'=>[],'selection'=>['blueprint_id'=>5,'provider_id'=>7,'variant_id'=>9]];}
 private function data():array{$p=$this->plan();return ['id'=>'product_1','blueprint_id'=>5,'print_provider_id'=>7,'variants'=>$p['request']['body']['variants'],'print_areas'=>$p['request']['body']['print_areas'],'images'=>[['src'=>'https://images.printify.com/example.png']]];}
 public function testAppliedProductIsProjectedWithoutCertifyingSupplier():void{$p=PrintifyProductResponse::project($this->plan(),$this->data());self::assertSame('product_1',$p['id']);self::assertFalse($p['supplier_certified']);}
 public function testIgnoredPriceAndUnappliedArtworkCannotBecomeSuccessfulReceipt():void{foreach(['price','image','provider'] as $kind){$d=$this->data();if($kind==='price')$d['variants'][0]['price']=100;elseif($kind==='image')$d['print_areas'][0]['placeholders'][0]['images'][0]['id']='wrong_image';else $d['print_provider_id']=8;try{PrintifyProductResponse::project($this->plan(),$d);self::fail('Scope mismatch accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}}}
 public function testWrongPreviewVariantAndTaskIdsAreRejected():void{$p=['request'=>['operation'=>'PREVIEW_READ','endpoint'=>'/v1/shops/12/products/product_1/personalization_previews/tasks/task_1.json','body'=>[]],'selection'=>['variant_id'=>9],'options'=>[]];$this->expectException(\InvalidArgumentException::class);PrintifyProductResponse::project($p,['task_id'=>'task_1','status'=>'completed','mockups'=>[['variant_id'=>99,'src'=>'https://images.printify.com/example.png']]]);}
 public function testBooleanPlacementCannotConfirmAnAppliedMutation():void{$d=$this->data();$d['print_areas'][0]['placeholders'][0]['images'][0]['scale']=true;$this->expectException(\InvalidArgumentException::class);PrintifyProductResponse::project($this->plan(),$d);}
}
