<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
use DigiForge\Database\Tables;
final class FulfillmentProviderScopeAcceptanceTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);}
 private function plan(string $shop):array {
  $order=(new DigiForge\Orders\Repository())->createOrder(['shop_reference'=>$shop,'channel'=>'etsy','environment'=>'sandbox']);self::assertNotWPError($order);global $wpdb;$now=current_time('mysql',true);$hash=hash('sha256',wp_generate_uuid4());self::assertSame(1,$wpdb->insert(Tables::fulfillment_plans(),['order_id'=>$order['id'],'plan_version'=>'fixture','provider'=>'printify','canonical_payload'=>'{}','payload_hash'=>$hash,'readiness'=>'{}','readiness_hash'=>$hash,'state'=>'DRAFT','environment'=>'sandbox','created_at'=>$now,'updated_at'=>$now]));return ['id'=>(int)$wpdb->insert_id,'order_id'=>$order['id']];
 }
 public function testStoredProviderPlansAreShopScopedBeforeTheWindow():void {
  $a=$this->plan('personalized_pod');$b=$this->plan('standard_pod');$state=null;$model=new DigiForge\Portal\OperationalDepthReadModel();$rows=$model->fulfillmentProviders(1,$state,'personalized_pod');self::assertSame('AVAILABLE',$state);self::assertSame($a['id'],(int)$rows[0]['id']);self::assertNotContains($b['id'],array_map('intval',array_column($rows,'id')));self::assertFalse($rows[0]['production_authorized']);$get=$_GET;
  try{$_GET=['df_view'=>'fulfillment','df_shop'=>'personalized_pod'];$html=(new DigiForge\Portal\Portal())->render();self::assertStringContainsString('<tr><td>#'.$a['id'].' fixture',$html);self::assertStringNotContainsString('<tr><td>#'.$b['id'].' fixture',$html);}finally{$_GET=$get;}
 }
 public function testProviderUnknownEvidenceRequiresSameShopAndIntactPackageBinding():void {
  global $wpdb;$now=current_time('mysql',true);$rows=[];
  foreach(['personalized_pod','standard_pod'] as $shop){$plan=$this->plan($shop);$hash=hash('sha256',wp_generate_uuid4());self::assertSame(1,$wpdb->insert(Tables::pod_authorization_packages(),['order_id'=>$plan['order_id'],'render_evidence_id'=>1,'provider_mapping_id'=>1,'ownership_mapping_id'=>1,'readiness_hash'=>$hash,'package_hash'=>$hash,'state'=>'APPROVED_PACKAGE','created_at'=>$now]));$package=(int)$wpdb->insert_id;
   self::assertSame(1,$wpdb->insert(Tables::pod_authorization_bindings(),['package_id'=>$package,'package_hash'=>$hash,'authorization_hash'=>$hash,'nonce_hash'=>$hash,'bound_by'=>get_current_user_id(),'bound_at'=>$now]));self::assertSame(1,$wpdb->insert(Tables::pod_execution_unknowns(),['action'=>'fixture','evidence_hash'=>$hash,'authorization_hash'=>$hash,'nonce_hash'=>$hash,'request_fingerprint'=>$hash,'reconciliation_identity'=>'{}','recorded_at'=>$now,'unknown_hash'=>$hash,'created_at'=>$now]));$rows[$shop]=['hash'=>$hash,'package'=>$package];
  }
  $model=new DigiForge\POD\ProviderStatusReadModel();$scope=$model->snapshot(50,'personalized_pod');self::assertSame('AVAILABLE',$scope['query_state']);self::assertContains($rows['personalized_pod']['hash'],array_column($scope['items'],'authorization_hash'));self::assertNotContains($rows['standard_pod']['hash'],array_column($scope['items'],'authorization_hash'));self::assertFalse($scope['production_authorized']);
  self::assertSame(1,$wpdb->update(Tables::pod_authorization_packages(),['package_hash'=>hash('sha256','drift')],['id'=>$rows['personalized_pod']['package']]));self::assertNotContains($rows['personalized_pod']['hash'],array_column($model->snapshot(50,'personalized_pod')['items'],'authorization_hash'));
 }
}
