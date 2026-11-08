<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use DigiForge\POD\PrintifyApiRequest as Request;
use DigiForge\POD\PrintifyApiReadEvidence as Evidence;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/POD/PrintifyApiRequest.php';
require_once __DIR__.'/../../includes/POD/PrintifyApiReadEvidence.php';
final class PrintifyProductReadApiTest extends TestCase {
 private function input():array{return ['product_id'=>'p1','blueprint_id'=>1,'provider_id'=>2,'variant_ids'=>[3]];}
 private function product():array{return ['id'=>'p1','shop_id'=>12,'blueprint_id'=>1,'print_provider_id'=>2,'variants'=>[['id'=>3,'price'=>100,'is_enabled'=>true]],'print_areas'=>[['variant_ids'=>[3],'placeholders'=>[['position'=>'front','images'=>[['id'=>'a','x'=>0.5,'y'=>0.5,'scale'=>1,'angle'=>0]]]]]],'images'=>[['src'=>'https://images.example.test/a.png','variant_ids'=>[3],'secret'=>'hidden']],'secret'=>'hidden'];}
 public function testDetailBindsSelection():void{$r=Request::prepare('PRODUCT_READ','12',$this->input());self::assertSame([],$r['body']);self::assertSame($this->input(),$r['input']);self::assertSame('/v1/shops/12/products/p1.json',$r['endpoint']);}
 public function testSafeProjection():void{self::assertTrue(Evidence::supported('PRODUCT_READ'));$r=Evidence::project('PRODUCT_READ',$this->product(),'12',$this->input());self::assertStringNotContainsString('hidden',json_encode($r));}
 public function testLocalPagination():void{$r=Evidence::project('PRODUCTS_READ',['data'=>[$this->product()],'current_page'=>1,'last_page'=>2,'per_page'=>1,'next_page_url'=>'https://evil.test'],'12',['page'=>1,'limit'=>1]);self::assertSame(['page'=>2,'limit'=>1],$r['next_page']);self::assertFalse($r['pagination_followed']);}
 /** @dataProvider mutations */
 public function testRejectsInvalidProduct(string $key):void{$p=$this->product();switch($key){case 'id':$p['id']='other';break;case 'shop':$p['shop_id']=13;break;case 'blueprint':$p['blueprint_id']=2;break;case 'variant':$p['variants'][0]['id']=4;break;case 'area':$p['print_areas'][0]['variant_ids']=[4];break;case 'url':$p['images'][0]['src']='http://localhost/a';break;case 'deleted':$p['is_deleted']=true;break;case 'provider':$p['print_provider_id']=9;break;case 'image_variant':$p['images'][0]['variant_ids']=[9];break;case 'missing':unset($p['variants']);break;case 'size':$p['extra']=str_repeat('x',300000);break;}$this->expectException(\InvalidArgumentException::class);Evidence::project('PRODUCT_READ',$p,'12',$this->input());}
 public static function mutations():array{return array_map(fn($x)=>[$x],['id','shop','blueprint','variant','area','url','deleted','size','provider','image_variant','missing']);}
 public function testSelectionChangesFingerprint():void{$a=$this->input();$b=$a;$b['variant_ids']=[4];self::assertNotSame(Request::prepare('PRODUCT_READ','12',$a)['fingerprint'],Request::prepare('PRODUCT_READ','12',$b)['fingerprint']);}
 public function testDuplicatePageProductsFail():void{$this->expectException(\InvalidArgumentException::class);Evidence::project('PRODUCTS_READ',['data'=>[$this->product(),$this->product()],'current_page'=>1,'last_page'=>1,'per_page'=>2],'12',['limit'=>2]);}
 public function testStrictPagination():void{$this->expectException(\InvalidArgumentException::class);Request::prepare('PRODUCTS_READ','12',['page'=>'1','limit'=>1]);}
 public function testEmptyPage():void{$r=Evidence::project('PRODUCTS_READ',['data'=>[],'current_page'=>1,'last_page'=>1,'per_page'=>10],'12',[]);self::assertNull($r['next_page']);}
 public function testProductSelectionFingerprintSurvivesCanonicalPersistence():void{
  $input=['product_id'=>'p1','blueprint_id'=>5,'provider_id'=>7,'variant_ids'=>[3]];
  $a=\DigiForge\POD\PrintifyApiRequest::prepare('PRODUCT_READ','12',$input);$b=\DigiForge\POD\PrintifyApiRequest::prepare('PRODUCT_READ','12',array_reverse($input,true));self::assertSame($a['fingerprint'],$b['fingerprint']);
 }
}
