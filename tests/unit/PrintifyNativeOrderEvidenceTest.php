<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\POD\{PrintifyNativeOrderEvidence,PrintifyOrderEvidence};
require_once __DIR__.'/../../includes/POD/PrintifyNativeOrderEvidence.php';
require_once __DIR__.'/../../includes/POD/PrintifyOrderEvidence.php';
final class PrintifyNativeOrderEvidenceTest extends TestCase
{
 private function fixture():array {
  $native=['personalisation_strategy'=>'pstudio_pre_order_headless','personalisation_instructions'=>'opaque-provider-reference'];
  $scope=['installation_sha256'=>'install','environment'=>'production','business_id'=>1,'store_id'=>2,'program_id'=>3,'shop_key'=>'personalized_pod','provider'=>'printify','external_account_reference'=>'account','external_shop_reference'=>'12','integration_id'=>7,'capability'=>'PROVIDER_ORDER_SUBMIT','binding_hash'=>'order-binding'];
  $plan=['workflow'=>'workflow','package'=>'package','integration_id'=>7,'shop_id'=>'12','connection_scope'=>array_replace($scope,['capability'=>'printify.product.prepare','binding_hash'=>'product-binding']),'selection'=>['variant_id'=>9],'request'=>['operation'=>'PERSONALIZATION_CONFIGURE','endpoint'=>'/v1/shops/12/products/p1/personalization.json','body'=>['variant_id'=>9]]];
  $receipt=['status'=>'SUCCEEDED','operation'=>'PERSONALIZATION_CONFIGURE','network_request_attempted'=>true,'audit_required'=>true,'integration_id'=>7,'shop_id'=>'12','workflow'=>'workflow','package'=>'package','connection_binding_hash'=>'product-binding','data'=>$native];
  return [['product_id'=>'p1','variant_id'=>9,'quantity'=>1,'personalisation'=>$native],$receipt,$plan,$scope];
 }
 public function testExactProviderValuesAndStableAccountAllowDifferentCapabilityBindings():void {
  [$line,$receipt,$plan,$scope]=$this->fixture();PrintifyNativeOrderEvidence::validateResolved($line,$receipt,$plan,7,12,$scope);self::assertTrue(true);
 }
 public function testWrongProductVariantAccountPackageOperationAndSyntheticReceiptsFail():void {
  foreach(['product','variant','account','package','operation','synthetic','strategy','instructions','audit'] as $case){
   [$line,$receipt,$plan,$scope]=$this->fixture();
   switch($case){case 'product':$line['product_id']='other';break;case 'variant':$line['variant_id']=10;break;case 'account':$scope['external_account_reference']='other';break;case 'package':$receipt['package']='other';break;case 'operation':$receipt['operation']='PERSONALIZATION_OPTIONS';break;case 'synthetic':$receipt['network_request_attempted']=false;break;case 'strategy':$line['personalisation']['personalisation_strategy']='manual';break;case 'instructions':$line['personalisation']['personalisation_instructions']='invented';break;case 'audit':$receipt['audit_required']=false;break;}
   try{PrintifyNativeOrderEvidence::validateResolved($line,$receipt,$plan,7,12,$scope);self::fail($case.' accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}
  }
 }
 public function testRawNativeOrderCannotPassExistingOrderValidator():void {
  [$line]=$this->fixture();$this->expectException(\InvalidArgumentException::class);PrintifyOrderEvidence::validate(['external_id'=>'order','line_items'=>[$line]]);
 }
 public function testConfigurationRejectsManualMissingExtraAndMalformedValues():void {
  foreach([[],['personalisation_strategy'=>'manual','personalisation_instructions'=>'text'],['personalisation_strategy'=>'pstudio_pre_order_headless','personalisation_instructions'=>"bad\nvalue"],['personalisation_strategy'=>'pstudio_pre_order_headless','personalisation_instructions'=>'token','raw_grant'=>true]] as $value){try{PrintifyNativeOrderEvidence::configuration($value);self::fail('Invalid configuration accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}}
 }
}
