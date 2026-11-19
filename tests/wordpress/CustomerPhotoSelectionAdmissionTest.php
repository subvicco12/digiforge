<?php
declare(strict_types=1);
require_once __DIR__.'/RenderArtifactCertificationTest.php';
use DigiForge\POD\CustomerPhotoSelection;
require_once __DIR__.'/fixtures/PhotoWorkflowFixtureIsolation.php';
final class CustomerPhotoSelectionAdmissionTest extends WP_UnitTestCase {
 use PhotoWorkflowFixtureIsolation;
 protected function setUp():void {
  parent::setUp();DigiForge\Core\Activator::activate();ShopAccessFixture::reset();global $wpdb;$t=DigiForge\Database\Tables::class;
  // Review/schema DDL may commit fixtures. Isolate this class's fixed synthetic identities between cases.
  $wpdb->query("DELETE usage_row FROM ".$t::shop_ai_usage()." usage_row INNER JOIN ".$t::pod_production_templates()." template ON template.fingerprint=usage_row.model_key WHERE template.template_id='personalized_pod' AND usage_row.workflow='production_template'");
  $wpdb->query("DELETE ps FROM ".$t::personalization_submissions()." ps INNER JOIN ".$t::order_line_items()." li ON li.id=ps.order_line_item_id INNER JOIN ".$t::orders()." o ON o.id=li.order_id WHERE o.external_order_reference='personalized_pod' AND o.shop_reference='personalized_pod'");
  $wpdb->query("DELETE li FROM ".$t::order_line_items()." li INNER JOIN ".$t::orders()." o ON o.id=li.order_id WHERE o.external_order_reference='personalized_pod' AND o.shop_reference='personalized_pod'");
  $wpdb->delete($t::orders(),['external_order_reference'=>'personalized_pod','shop_reference'=>'personalized_pod']);
  $wpdb->delete($t::pod_mappings(),['mapping_version'=>'personalized_pod','provider_product_key'=>'101','product_version_id'=>1]);
  $wpdb->delete($t::pod_production_templates(),['template_id'=>'personalized_pod']);
  $wpdb->delete($t::personalization_schemas(),['product_version_id'=>1,'schema_key'=>'name','version_label'=>'1']);
  $wpdb->delete($t::businesses(),['business_key'=>'digicraftifygoods','display_name'=>'Fixture goods']);
  $this->capturePhotoFixtureState();
 }
 private function fixture():array {DigiForge\Core\Activator::activate();ShopAccessFixture::reset();$f=new RenderArtifactCertificationTest();return (new ReflectionMethod($f,'deterministicFixture'))->invoke($f);}
 public function testAuthoritativeUnmanagedResultRequiresExactScopeAndNoRetainedManagedMarker():void {
  $render=$this->fixture();global $wpdb;$line=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DigiForge\Database\Tables::order_line_items().' WHERE order_id=%d',$render['order_id']));$service=new CustomerPhotoSelection();
  self::assertTrue(method_exists($service,'resolveForOrder'),'Lane B requires authoritative unmanaged discrimination, not translation of arbitrary errors.');
  self::assertNull($service->resolveForOrder('personalized_pod',$render['order_id'],$line));self::assertNull($service->resolveForOrder('personalized_pod',$render['order_id'],$line,0));self::assertWPError($service->forOrder('personalized_pod',$render['order_id'],$line));self::assertWPError($service->resolveForOrder('personalized_pod',$render['order_id'],$line,1));
  self::assertWPError($service->resolveForOrder('digital',$render['order_id'],$line));self::assertWPError($service->resolveForOrder('personalized_pod',$render['order_id']+1,$line));self::assertWPError($service->resolveForOrder('personalized_pod',$render['order_id'],$line+1));
  $actor=get_current_user_id();wp_set_current_user(self::factory()->user->create(['role'=>'subscriber']));self::assertWPError($service->resolveForOrder('personalized_pod',$render['order_id'],$line));wp_set_current_user($actor);
  self::assertTrue(DigiForge\Security\Logger::write('photo_editor_changed',['version_sha256'=>hash('sha256','synthetic lost draft')],'photo_input',(string)$render['order_id']));self::assertWPError($service->resolveForOrder('personalized_pod',$render['order_id'],$line));
 }
 public function testUnavailableMarkerReadNeverReturnsLegacyNull():void {
  $render=$this->fixture();global $wpdb;$line=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DigiForge\Database\Tables::order_line_items().' WHERE order_id=%d',$render['order_id']));$service=new CustomerPhotoSelection();self::assertTrue(method_exists($service,'resolveForOrder'));
  $fault=static fn($sql)=>str_contains($sql,'COUNT(*)')&&str_contains($sql,'photo_editor_changed')?'SELECT missing_photo_marker_read':$sql;add_filter('query',$fault);try{self::assertWPError($service->resolveForOrder('personalized_pod',$render['order_id'],$line));}finally{remove_filter('query',$fault);}
 }
}
