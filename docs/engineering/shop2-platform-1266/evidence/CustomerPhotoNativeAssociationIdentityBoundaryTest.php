<?php
declare(strict_types=1);
use DigiForge\Database\Tables;
use DigiForge\Orders\Repository;
require_once dirname(__DIR__,4).'/tests/wordpress/CustomerPhotoHeldIntegrationTest.php';
/** Expected identity-domain denial without a typed bridge; outside default acceptance. */
final class CustomerPhotoNativeAssociationIdentityBoundaryTest extends CustomerPhotoHeldIntegrationTest {
 public function testCatalogPhotoMappingCannotBeAssumedSavedProviderProductIdentity():void {
  $fixture=(new ReflectionProperty(CustomerPhotoHeldIntegrationTest::class,'fixture'))->getValue($this);
  $scope=(new ReflectionMethod(CustomerPhotoHeldIntegrationTest::class,'scope'))->invoke($this);$readWorkflow=new \DigiForge\POD\PrintifyApiReadWorkflow();$readPlan=PrintifyApiApprovalFixture::checked($readWorkflow->prepare('personalized_pod',$fixture->integration,'ORDER_READ',['order_id'=>'synthetic_photo_order']));$readApproval=$fixture->approve($readWorkflow,$readPlan,'printify-api-read');$readReceipt=PrintifyApiApprovalFixture::checked((new \DigiForge\POD\PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response(['id'=>'synthetic_photo_order','status'=>'pending','line_items'=>[['id'=>'provider-photo-line','product_id'=>'101','variant_id'=>202,'quantity'=>1]],'shipments'=>[]])))->executeApiRead($readPlan['hash'],$readApproval['hash']));
  $f=$this->photo();global $wpdb;$line=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::order_line_items().' WHERE id=%d',$f['line']),ARRAY_A);$repo=new Repository();$plan=PrintifyApiApprovalFixture::checked($repo->createPlan(['order_id'=>$f['order'],'plan_version'=>'catalog-saved-product-frontier','provider'=>'printify']));foreach(['REVIEW_REQUIRED','APPROVED']as $state)PrintifyApiApprovalFixture::checked($repo->transition('plan',(int)$plan['id'],$state));$associations=[['provider_line_id'=>'provider-photo-line','local_line_id'=>$f['line'],'product_version_id'=>(int)$line['product_version_id'],'provider_mapping_id'=>(int)$line['provider_mapping_id'],'quantity'=>1]];
  $proposal=\DigiForge\Connector\Printify\NativeBusinessOrderEvidence::prepareEvidence($scope,get_current_user_id(),$f['order'],$readReceipt['hash'],(int)$plan['id'],$associations);self::assertWPError($proposal,'Catalog-only inputs must not be treated as a saved provider product.');self::assertSame('printify_native_order_product_mismatch',$proposal->get_error_code());$denial=$proposal->get_error_data();self::assertFalse($denial['external_execution_authorized']);self::assertFalse($denial['network_request_attempted']);self::assertFalse($denial['retry_permitted']);
 }
}
