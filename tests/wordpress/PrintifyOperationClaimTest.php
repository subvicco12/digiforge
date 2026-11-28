<?php
final class PrintifyOperationClaimTest extends WP_UnitTestCase{
 public function testCreationIntentSurvivesConnectionRevisionAndMissingSnapshot():void{
  global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');$scope=['external_account_reference'=>'fixture-'.wp_generate_uuid4(),'external_shop_reference'=>'12','binding_hash'=>str_repeat('a',64)];$fp=hash('sha256','fixture-product');
  try{self::assertTrue(DigiForge\POD\PrintifyOperationClaim::claim($scope,'PRODUCT_CREATE',$fp,$fp));$scope['binding_hash']=str_repeat('b',64);$again=DigiForge\POD\PrintifyOperationClaim::claim($scope,'PRODUCT_CREATE',$fp,$fp);self::assertWPError($again);self::assertSame('printify_intent_consumed',$again->get_error_code());
   $hash=hash('sha256',json_encode([$scope['external_account_reference'],'12','PRODUCT_CREATE',$fp],JSON_THROW_ON_ERROR));$wpdb->delete($wpdb->options,['option_name'=>'digiforge_pfy_intent_'.$hash]);self::assertWPError(DigiForge\POD\PrintifyOperationClaim::claim($scope,'PRODUCT_CREATE',$fp,$fp));
  }finally{if(isset($hash)){$wpdb->delete($wpdb->options,['option_name'=>'digiforge_pfy_intent_'.$hash]);$wpdb->delete(DigiForge\Database\Tables::audit_log(),['object_type'=>'printify_intent','object_id'=>$hash]);}$wpdb->query('START TRANSACTION');}
 }
 public function testOuterTransactionAndUnknownScopeDoNotClaim():void{self::assertWPError(DigiForge\POD\PrintifyOperationClaim::claim([],'PRODUCT_CREATE','id',str_repeat('a',64)));self::assertWPError(DigiForge\POD\PrintifyOperationClaim::claim(['external_account_reference'=>'fixture','external_shop_reference'=>'12'],'PRODUCT_CREATE','id',str_repeat('a',64)));}
}
