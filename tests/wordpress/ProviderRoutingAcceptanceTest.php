<?php
declare(strict_types=1);
require_once __DIR__.'/PrintifyPreflightTest.php';
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\POD\PrintifyProductionPreflight;
/** Blueprint table11r3c3: Printify Phase-1 eligibility, not supplier ranking or live certification. */
final class ProviderRoutingAcceptanceTest extends WP_UnitTestCase
{
 public function testOtherProviderCannotReusePrintifyPhaseOneEligibilityOrInvokeAnAdapter():void {
  DigiForge\Core\Activator::activate();Settings::protectProduction();global $wpdb;
  // Schema DDL can end the parent test transaction; all following rows stay disposable.
  self::assertNotFalse($wpdb->query('START TRANSACTION'));
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
  // Reuse the exact established local synthetic package/geometry builders, not new supplier values.
  $fixture=new PrintifyPreflightTest('testValidatedTemplateRouteIsRequiredAndDryRunNeverAuthorizesExecution');
  $packageId=(new ReflectionMethod($fixture,'seedReadyPackage'))->invoke($fixture,current_time('mysql',true),321,'77:88');
  $template=(new ReflectionMethod($fixture,'saveTemplate'))->invoke($fixture,['template_id'=>'provider-route-'.wp_generate_uuid4(),'template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>321,'provider_id'=>77,'variant_ids'=>[88],'print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>1200,'height_px'=>1600]],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'NAME_MONOGRAM','template_status'=>'VALIDATED']);
  self::assertNotWPError($template);global $wpdb;
  $package=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_authorization_packages().' WHERE id=%d',$packageId),ARRAY_A);self::assertIsArray($package);
  $mappingId=(int)$package['provider_mapping_id'];
  $calls=0;$http=static function()use(&$calls){++$calls;return new WP_Error('provider_routing_offline','All provider requests forbidden.');};add_filter('pre_http_request',$http);
  try {
   $before=(new PrintifyProductionPreflight())->evaluate($packageId);self::assertNotWPError($before);
   self::assertNotContains('PRINTIFY_MAPPING_NOT_CERTIFIED',$before['blockers']);self::assertFalse($before['ready_for_external_execution']);
   self::assertSame(1,$wpdb->update(Tables::pod_mappings(),['provider'=>'gelato'],['id'=>$mappingId]));
   $after=(new PrintifyProductionPreflight())->evaluate($packageId);self::assertNotWPError($after);
   self::assertContains('PRINTIFY_MAPPING_NOT_CERTIFIED',$after['blockers']);self::assertFalse($after['ready_for_dry_run']);self::assertFalse($after['ready_for_external_execution']);
   self::assertSame('gelato',$after['evidence']['provider']);self::assertFalse($after['evidence']['external_execution_authorized']);self::assertFalse($after['evidence']['external_execution_performed']);
   $proposal=DigiForge\POD\ProviderRouter::prepare('gelato','sandbox','PREPARE_ORDER',['fixture_only'=>true]);self::assertNotWPError($proposal);
   self::assertFalse($proposal['adapter_invoked']);self::assertFalse($proposal['external_execution_performed']);
   self::assertSame($package,$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_authorization_packages().' WHERE id=%d',$packageId),ARRAY_A));
   self::assertSame(0,$calls);self::assertTrue(Settings::get('stop_all',true));self::assertTrue(Settings::safety_locked());self::assertFalse(Settings::get('automation_armed',false));
  }finally{remove_filter('pre_http_request',$http);$wpdb->update(Tables::pod_mappings(),['provider'=>'printify'],['id'=>$mappingId]);Settings::protectProduction();}
 }
}
