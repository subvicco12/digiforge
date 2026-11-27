<?php
declare(strict_types=1);
final class PrintifyNativeOrderBoundaryTest extends WP_UnitTestCase
{
 private function payload():array {
  return ['integration_id'=>7,'shop_id'=>12,'order'=>['external_id'=>'native-order','line_items'=>[['product_id'=>'p1','variant_id'=>9,'quantity'=>1]],'shipping_method'=>1,'is_printify_express'=>false,'is_economy_shipping'=>false,'send_shipping_notification'=>false,'address_to'=>['first_name'=>'Test','last_name'=>'Buyer','email'=>'buyer@example.test','phone'=>'','country'=>'US','region'=>'CA','address1'=>'1 Example Street','address2'=>'','city'=>'Example','zip'=>'90210']]];
 }
 public function testExistingOrderBodyAndFingerprintAreUnchangedWithoutNativeMetadata():void {
  $payload=$this->payload();$r=DigiForge\POD\PrintifyMutationRequest::prepare(['action'=>'PROVIDER_ORDER_SUBMIT'],$payload);self::assertIsArray($r);self::assertSame($payload['order'],$r['body']);self::assertArrayNotHasKey('native_configuration_references',$r['reconciliation_identity']);
  $payload['native_configuration_references']=[];$same=DigiForge\POD\PrintifyMutationRequest::prepare(['action'=>'PROVIDER_ORDER_SUBMIT'],$payload);self::assertSame($r['request_fingerprint'],$same['request_fingerprint']);
 }
 public function testRawUnboundNativeAndOrphanMetadataCannotPrepareRequests():void {
  $payload=$this->payload();$payload['order']['line_items'][0]['personalisation']=['personalisation_strategy'=>'pstudio_pre_order_headless','personalisation_instructions'=>'invented'];
  self::assertWPError(DigiForge\POD\PrintifyMutationRequest::prepare(['action'=>'PROVIDER_ORDER_SUBMIT'],$payload));
  $payload=$this->payload();$payload['native_configuration_references']=[['line_index'=>0,'configuration_receipt'=>str_repeat('a',64)]];
  self::assertWPError(DigiForge\POD\PrintifyMutationRequest::prepare(['action'=>'PROVIDER_ORDER_SUBMIT'],$payload));
 }
 public function testMalformedDuplicateAndMissingConfigurationCannotFallback():void {
  foreach([null,'raw-token',[['line_index'=>'0','configuration_receipt'=>'raw']],[['line_index'=>0,'configuration_receipt'=>str_repeat('a',64)],['line_index'=>0,'configuration_receipt'=>str_repeat('a',64)]]] as $refs){
   $payload=$this->payload();$payload['order']['line_items'][0]['personalisation']=['personalisation_strategy'=>'pstudio_pre_order_headless','personalisation_instructions'=>'invented'];$payload['native_configuration_references']=$refs;
   self::assertWPError(DigiForge\POD\PrintifyMutationRequest::prepare(['action'=>'PROVIDER_ORDER_SUBMIT'],$payload));
  }
 }
}
