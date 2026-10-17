<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/POD/PrintifyCatalogReadContract.php';
use DigiForge\POD\PrintifyCatalogReadContract as Contract;
function check(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
function denies(callable $fn):void {try{$fn();}catch(InvalidArgumentException){return;}throw new RuntimeException('Expected fail-closed rejection');}
$scope=['kind'=>'shipping','blueprint_id'=>1220,'provider_id'=>105];
check(Contract::endpoint($scope)==='/v2/catalog/blueprints/1220/print_providers/105/shipping.json','Exact shipping path');
check(Contract::select(['profiles'=>[['countries'=>['US'],'first_item'=>['cost'=>769,'currency'=>'USD']]]],$scope)['profiles'][0]['countries'][0]==='US','Shipping envelope');
denies(fn()=>Contract::endpoint(['kind'=>'shipping','blueprint_id'=>1220,'provider_id'=>105,'method'=>'POST']));
denies(fn()=>Contract::endpoint(['kind'=>'shipping','blueprint_id'=>0,'provider_id'=>105]));
denies(fn()=>Contract::endpoint(['kind'=>'shipping','blueprint_id'=>1220,'provider_id'=>105,'shop_id'=>1]));
denies(fn()=>Contract::select([],$scope));
denies(fn()=>Contract::select(['profiles'=>['bad'=>new stdClass()]],$scope));
denies(fn()=>Contract::select(['profiles'=>['note'=>str_repeat('x',2001)]],$scope));
check(Contract::endpoint(['kind'=>'variants','blueprint_id'=>1220,'provider_id'=>105])==='/v1/catalog/blueprints/1220/print_providers/105/variants.json','Existing variants unchanged');
echo "Printify scoped shipping contract smoke PASS\n";
