<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/POD/PrintifyCatalogReadContract.php';
use DigiForge\POD\PrintifyCatalogReadContract as Contract;
function check(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
function denies(callable $fn):void {try{$fn();}catch(InvalidArgumentException){return;}throw new RuntimeException('Expected fail-closed rejection');}
$scope=['kind'=>'shipping','blueprint_id'=>1220,'provider_id'=>105];
check(Contract::endpoint($scope)==='/v2/catalog/blueprints/1220/print_providers/105/shipping.json','Exact shipping path');
$methods=json_decode(file_get_contents(__DIR__.'/fixtures/printify/shipping-v2-methods.json'),true,32,JSON_THROW_ON_ERROR);
check(Contract::select($methods,$scope)===$methods,'V2 method discovery envelope');
denies(fn()=>Contract::select(['profiles'=>[]],$scope));
$rates=json_decode(file_get_contents(__DIR__.'/fixtures/printify/shipping-v2-standard.json'),true,32,JSON_THROW_ON_ERROR);
$methodScope=['kind'=>'shipping_method','blueprint_id'=>1220,'provider_id'=>105,'method'=>'standard'];
check(Contract::endpoint($methodScope)==='/v2/catalog/blueprints/1220/print_providers/105/shipping/standard.json','Exact method-specific path');
check(Contract::select($rates,$methodScope)===$rates,'V2 rate record associations');
denies(fn()=>Contract::select($rates,$scope));
denies(fn()=>Contract::select($methods,$methodScope));
denies(fn()=>Contract::endpoint(['kind'=>'shipping','blueprint_id'=>1220,'provider_id'=>105,'method'=>'POST']));
denies(fn()=>Contract::endpoint(['kind'=>'shipping','blueprint_id'=>0,'provider_id'=>105]));
denies(fn()=>Contract::endpoint(['kind'=>'shipping','blueprint_id'=>1220,'provider_id'=>105,'shop_id'=>1]));
denies(fn()=>Contract::select([],$scope));
denies(fn()=>Contract::select(['profiles'=>['bad'=>new stdClass()]],$scope));
denies(fn()=>Contract::select(['profiles'=>['note'=>str_repeat('x',2001)]],$scope));
check(Contract::endpoint(['kind'=>'variants','blueprint_id'=>1220,'provider_id'=>105])==='/v1/catalog/blueprints/1220/print_providers/105/variants.json','Existing variants unchanged');
echo "Printify scoped shipping contract smoke PASS\n";
