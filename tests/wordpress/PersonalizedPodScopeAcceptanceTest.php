<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
use DigiForge\Database\Tables;
use DigiForge\POD\PersonalizedPodOperationsReadModel;
final class PersonalizedPodScopeAcceptanceTest extends WP_UnitTestCase
{
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],DigiForge\Core\Capabilities::ALL);}
 private function package(string $shop):int {
  $order=(new DigiForge\Orders\Repository())->createOrder(['shop_reference'=>$shop,'channel'=>'etsy','environment'=>'sandbox']);self::assertNotWPError($order);global $wpdb;$hash=hash('sha256',wp_generate_uuid4());self::assertSame(1,$wpdb->insert(Tables::pod_authorization_packages(),['order_id'=>$order['id'],'render_evidence_id'=>1,'provider_mapping_id'=>1,'ownership_mapping_id'=>1,'readiness_hash'=>$hash,'package_hash'=>$hash,'state'=>'REVIEW_REQUIRED','created_at'=>current_time('mysql',true)]));return (int)$wpdb->insert_id;
 }
 public function testShopPredicatePrecedesWindowAndDoesNotGrantProduction():void {
  $own=$this->package('personalized_pod');$foreign=$this->package('standard_pod');$unknown=$this->package('unmapped-shop');$rows=(new PersonalizedPodOperationsReadModel())->snapshot(1);self::assertSame('AVAILABLE',$rows['query_state']);self::assertSame([$own],array_column($rows['items'],'package_id'));self::assertNotContains($foreign,array_column($rows['items'],'package_id'));self::assertNotContains($unknown,array_column($rows['items'],'package_id'));self::assertFalse($rows['external_execution_authorized']);self::assertFalse($rows['external_execution_performed']);self::assertFalse($rows['retry_permitted']);
 }
 public function testUnassignedAndInvalidScopesFailClosed():void {
  $this->package('standard_pod');$model=new PersonalizedPodOperationsReadModel();foreach(['standard_pod','all','DigiCraftifyGoods',''] as $shop){$rows=$model->snapshot(1,$shop);self::assertSame('UNAVAILABLE',$rows['query_state']);self::assertSame([],$rows['items']);self::assertNull($rows['counts']['packages']);}wp_set_current_user(0);self::assertSame('UNAVAILABLE',$model->snapshot()['query_state']);
 }
 public function testDatabaseReadFailureIsNotAnEmptyVerifiedQueue():void {
  $filter=static function(string $sql):string{return str_contains($sql,'FROM '.Tables::pod_authorization_packages().' p INNER JOIN')?'SELECT * FROM deliberately_missing_personalized_scope_table':$sql;};global $wpdb;$suppress=$wpdb->suppress_errors(true);add_filter('query',$filter);try{$rows=(new PersonalizedPodOperationsReadModel())->snapshot();self::assertSame('UNAVAILABLE',$rows['query_state']);self::assertNull($rows['counts']['packages']);self::assertSame([],$rows['items']);}finally{remove_filter('query',$filter);$wpdb->suppress_errors($suppress);}
 }
 public function testRenderRowsAreShopScopedBeforeTheWindow():void {
  global $wpdb;$ids=[];foreach(['personalized_pod','standard_pod','unmapped-shop'] as $shop){$order=(new DigiForge\Orders\Repository())->createOrder(['shop_reference'=>$shop,'channel'=>'etsy','environment'=>'sandbox']);self::assertNotWPError($order);$hash=hash('sha256',wp_generate_uuid4());self::assertSame(1,$wpdb->insert(Tables::pod_render_evidence(),['order_id'=>$order['id'],'provider_mapping_id'=>1,'template_key'=>'synthetic','template_version'=>'1','template_sha256'=>$hash,'personalization_evidence_hash'=>$hash,'render_mode'=>'DETERMINISTIC','output_sha256'=>$hash,'evidence_hash'=>$hash,'review_status'=>'UNREVIEWED','created_at'=>current_time('mysql',true)]));$ids[$shop]=(int)$wpdb->insert_id;}
  $model=new DigiForge\POD\RenderEvidenceOperationsReadModel();$rows=$model->snapshot(1);self::assertSame('AVAILABLE',$rows['query_state']);self::assertSame([$ids['personalized_pod']],array_column($rows['items'],'render_evidence_id'));self::assertFalse($rows['external_execution_authorized']);self::assertFalse($rows['external_execution_performed']);foreach(['standard_pod','all','DigiCraftifyGoods',''] as $shop)self::assertSame('UNAVAILABLE',$model->snapshot(1,$shop)['query_state']);wp_set_current_user(0);self::assertSame('UNAVAILABLE',$model->snapshot()['query_state']);
 }
 public function testRenderReadFailureIsUnavailable():void {
  $filter=static function(string $sql):string{return str_contains($sql,'FROM '.Tables::pod_render_evidence().' r INNER JOIN')?'SELECT * FROM deliberately_missing_render_scope_table':$sql;};global $wpdb;$suppress=$wpdb->suppress_errors(true);add_filter('query',$filter);try{$rows=(new DigiForge\POD\RenderEvidenceOperationsReadModel())->snapshot();self::assertSame('UNAVAILABLE',$rows['query_state']);self::assertNull($rows['counts']['renders']);self::assertSame([],$rows['items']);}finally{remove_filter('query',$filter);$wpdb->suppress_errors($suppress);}
 }
 public function testActualPortalUsesSelectedShopInsteadOfFixedPersonalizedScope():void {
  ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);$own=$this->package('personalized_pod');$foreign=$this->package('standard_pod');$old=$_GET;try{$_GET=['df_view'=>'pod_personalized','df_shop'=>'standard_pod'];$html=(new DigiForge\Portal\Portal())->render();self::assertStringContainsString('#'.$foreign.'</td>',$html);self::assertStringNotContainsString('#'.$own.'</td>',$html);}finally{$_GET=$old;}
 }
}
