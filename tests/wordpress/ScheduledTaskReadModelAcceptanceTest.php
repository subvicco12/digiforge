<?php
declare(strict_types=1);
require_once __DIR__ . '/fixtures/ShopAccessFixture.php';
final class ScheduledTaskReadModelAcceptanceTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();}
 public function testActualWpCronTasksAppearAsSafeReadOnlyMetadataWithoutSchedulingOrDispatch():void {
  ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);
  $args=[2,'digital','fixture-secret-run-key','manifest_poll'];$when=time()+3600;self::assertTrue(wp_schedule_single_event($when,'digiforge_u3_build_product_stage',$args,true));$before=_get_cron_array();
  $r=(new DigiForge\Queue\ScheduledTaskReadModel())->snapshot();self::assertSame('AVAILABLE',$r['query_state']['wp_cron']);$matches=array_values(array_filter($r['items'],static fn($x)=>$x['scheduler']==='WP_CRON'&&$x['candidate_id']===2&&$x['stage']==='manifest_poll'));self::assertCount(1,$matches);self::assertSame($when,$matches[0]['scheduled_at']);self::assertSame(hash('sha256',$args[2]),$matches[0]['run_key_sha256']);self::assertStringNotContainsString($args[2],wp_json_encode($r));self::assertSame($before,_get_cron_array());self::assertTrue($r['read_only']);self::assertFalse($r['retry_permitted']);self::assertTrue(DigiForge\Core\Settings::safety_locked());
  $get=$_GET;try{$_GET=['df_view'=>'automation','df_shop'=>'digital'];$html=(new DigiForge\Portal\Portal())->render();}finally{$_GET=$get;}
  self::assertStringContainsString('Product Factory scheduled tasks',$html);self::assertStringContainsString('WP_CRON',$html);self::assertStringContainsString('<td>manifest_poll</td>',$html);self::assertStringContainsString('<td>PENDING</td><td>NO</td>',$html);self::assertStringNotContainsString($args[2],$html);self::assertSame($before,_get_cron_array());
 }
 public function testUnavailableCronReadDoesNotBecomeAnEmptyAvailableScheduler():void {
  global $wpdb;$filter=static fn($sql)=>str_contains($sql,'SELECT option_value FROM '.$wpdb->options.' WHERE option_name=')?'SELECT option_value FROM digiforge_missing_scheduler_read':$sql;$before=_get_cron_array();$previous=$wpdb->suppress_errors(true);add_filter('query',$filter);
  try{$r=(new DigiForge\Queue\ScheduledTaskReadModel())->snapshot();}finally{remove_filter('query',$filter);$wpdb->suppress_errors($previous);$wpdb->last_error='';}
  self::assertSame('UNAVAILABLE',$r['query_state']['wp_cron']);self::assertSame([],$r['items']);self::assertSame($before,_get_cron_array());self::assertFalse($r['external_execution_authorized']);
 }
}
