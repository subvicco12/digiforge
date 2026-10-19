<?php
declare(strict_types=1);

use DigiForge\POD\PrintifyCatalogReadContract as Contract;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/POD/PrintifyCatalogReadContract.php';

final class PrintifyCatalogShippingContractTest extends TestCase {
 private function scope(string $kind='shipping'):array {return ['kind'=>$kind,'blueprint_id'=>1220,'provider_id'=>105]+($kind==='shipping_method'?['method'=>'standard']:[]);}
 private function fixture(string $name):array {return json_decode(file_get_contents(__DIR__.'/../fixtures/printify/shipping-v2-'.$name.'.json'),true,32,JSON_THROW_ON_ERROR);}
 public function testDiscoveryRequiresMethodListInsteadOfLegacyProfiles():void {
  $this->expectException(InvalidArgumentException::class);
  Contract::select(['profiles'=>[['countries'=>['US'],'first_item'=>['cost'=>769,'currency'=>'USD']]]],$this->scope());
 }
 public function testDocumentedDiscoveryAndEmptyListPreserveUnverifiedData():void {
  $data=$this->fixture('methods');self::assertSame($data,Contract::select($data,$this->scope()));self::assertSame(['data'=>[]],Contract::select(['data'=>[]],$this->scope()));
 }
 public function testAllFourMethodsRequireSeparateExactScopes():void {
  foreach(['standard','priority','express','economy']as $method){$scope=$this->scope('shipping_method');$scope['method']=$method;self::assertSame('/v2/catalog/blueprints/1220/print_providers/105/shipping/'.$method.'.json',Contract::endpoint($scope));
   $data=$this->fixture('standard');$data['data'][0]['attributes']['shippingType']=$method;$data['data'][0]['type']='variant_shipping_'.$method.'_us';self::assertSame($data,Contract::select($data,$scope));}
 }
 public function testRestOfWorldAndEmptyRatesAreNeverExpandedOrCertified():void {
  $data=$this->fixture('standard');$data['data'][0]['attributes']['country']['code']='REST_OF_THE_WORLD';$data['data'][0]['type']='variant_shipping_standard_rest_of_the_world';self::assertSame($data,Contract::select($data,$this->scope('shipping_method')));self::assertSame(['data'=>[]],Contract::select(['data'=>[]],$this->scope('shipping_method')));
 }
 /** @dataProvider invalidScopes */
 public function testUnsafeOrAmbiguousScopesAreRejected(array $scope):void {$this->expectException(InvalidArgumentException::class);Contract::endpoint($scope);}
 public static function invalidScopes():array {
  $r=['kind'=>'shipping_method','blueprint_id'=>1220,'provider_id'=>105,'method'=>'standard'];$cases=[];
  foreach(['POST','Standard',' standard','standard.json','../standard','standard?country=US','overnight','',null,1]as $method)$cases[]=[array_replace($r,['method'=>$method])];
  $missing=$r;unset($missing['method']);$cases[]=[$missing];
  foreach(['url'=>'https://evil.invalid','country'=>'US','variant_id'=>23494,'http_method'=>'GET','shop_id'=>1]as $k=>$v)$cases[]=[array_merge($r,[$k=>$v])];
  $cases[]=[array_replace($r,['kind'=>'shipping'])];return $cases;
 }
 /** @dataProvider invalidDiscovery */
 public function testMalformedDiscoveryFailsClosed(array $data):void {$this->expectException(InvalidArgumentException::class);Contract::select($data,$this->scope());}
 public static function invalidDiscovery():array {
  $row=['type'=>'shipping_method','id'=>'1','attributes'=>['name'=>'standard']];$cases=[[[]],[['data'=>null]],[['data'=>['bad'=>$row]]],[['data'=>[$row,$row]]],[['data'=>array_fill(0,1001,$row)]]];
  foreach([['type'=>'variant_shipping_standard_us'],['id'=>1],['attributes'=>[]],['attributes'=>['name'=>'overnight']],['attributes'=>['name'=>null]]]as $change)$cases[]=[['data'=>[array_replace($row,$change)]]];
  $cases[]=[['data'=>[$row],'profiles'=>[]]];return $cases;
 }
 /** @dataProvider invalidRates */
 public function testMalformedOrConflictingRateAssociationsFailClosed(string $path,mixed $value):void {
  $data=$this->fixture('standard');$node=&$data['data'][0];foreach(explode('.',$path)as $key)$node=&$node[$key];$node=$value;
  $this->expectException(InvalidArgumentException::class);Contract::select($data,$this->scope('shipping_method'));
 }
 public static function invalidRates():array {return [
  ['id','23495'],['type','variant_shipping_standard_gb'],['attributes',[]],['attributes.shippingType','priority'],['attributes.variantId',0],['attributes.variantId','23494'],['attributes.country',[]],['attributes.country.code',''],['attributes.country.code','USA'],['attributes.country.code','us'],['attributes.country.code','UNKNOWN'],['attributes.shippingPlanId',''],['attributes.handlingTime.from',-1],['attributes.handlingTime.from',9],['attributes.handlingTime.to',null],['attributes.shippingCost.firstItem.amount',-1],['attributes.shippingCost.firstItem.amount',3.99],['attributes.shippingCost.firstItem.currency','usd'],['attributes.shippingCost.additionalItems.currency','GBP'],['attributes.shippingCost',[]],['attributes.shippingCost.firstItem',null]
 ];}
 public function testDuplicateCountryVariantRatesCannotBecomeAmbiguousEvidence():void {
  $data=$this->fixture('standard');$data['data'][]=$data['data'][0];$this->expectException(InvalidArgumentException::class);Contract::select($data,$this->scope('shipping_method'));
 }
 public function testDiscoveryCannotBeUsedAsRatesOrRatesAsDiscovery():void {
  foreach([['methods','shipping_method'],['standard','shipping']]as [$fixture,$kind]){try{Contract::select($this->fixture($fixture),$this->scope($kind));self::fail('Different endpoint envelope accepted');}catch(InvalidArgumentException){self::assertTrue(true);}}
 }
 public function testResponseLinksCannotChangeProviderHostOrMethod():void {
  foreach(['https://evil.invalid/','https://api.printify.com/v2/catalog/blueprints/1220/print_providers/106/shipping/standard.json','https://api.printify.com/v2/catalog/blueprints/1220/print_providers/105/shipping/standard.json?shop=1']as $url){$data=$this->fixture('methods');$data['links']['standard']=$url;try{Contract::select($data,$this->scope());self::fail('Foreign shipping link accepted');}catch(InvalidArgumentException){self::assertTrue(true);}}
 }
 public function testShippingResourceAndStringLimitsFailClosed():void {
  foreach([['data'=>[new stdClass()]],['data'=>array_fill(0,1001,[])],['data'=>[],'links'=>['standard'=>str_repeat('x',2001)]]]as $data){try{Contract::select($data,$this->scope());self::fail('Unsafe response accepted');}catch(InvalidArgumentException){self::assertTrue(true);}}
 }

}
