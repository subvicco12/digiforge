<?php
use DigiForge\Queue\ScheduledTaskReadModel;
use DigiForge\Portal\SchedulerDependencyPanel;
require_once __DIR__.'/fixtures/PortalAccessFixture.php';
final class SchedulerDiagnostics15Test extends WP_UnitTestCase {
 public function testQueryExceptionPreservesDependencyAndRedactsException():void {
  $before=DigiForge\Queue\ActionSchedulerReadiness::inspect();
  $s=(new ScheduledTaskReadModel(static function(){throw new RuntimeException('private-provider-payload');},static fn()=>[]))->snapshot();
  self::assertSame($before,$s['action_scheduler_dependency']);self::assertSame('QUERY_EXCEPTION',$s['action_scheduler_query']['reason_code']);self::assertSame('UNAVAILABLE',$s['query_state']['action_scheduler']);self::assertSame('AVAILABLE',$s['query_state']['wp_cron']);self::assertStringNotContainsString('private-provider-payload',wp_json_encode($s));self::assertFalse($s['external_execution_authorized']);self::assertFalse($s['retry_permitted']);
 }
 public function testMalformedResultsRemainUnavailableAndIndependentCronSurvives():void {
  foreach([false,'private',[['id'=>1,'state'=>'PENDING','action'=>new stdClass]],array_fill(0,7,[])]as $frames){$s=(new ScheduledTaskReadModel(static fn()=>$frames,static fn()=>[]))->snapshot(1);self::assertSame('MALFORMED_RESULT',$s['action_scheduler_query']['reason_code']);self::assertSame('AVAILABLE',$s['query_state']['wp_cron']);self::assertSame([],$s['items']);}
 }
 public function testEmptySuccessfulQueryIsAvailableWithoutAuthorizingExecution():void {$s=(new ScheduledTaskReadModel(static fn()=>[],static fn()=>[]))->snapshot();self::assertSame('QUERY_PASSED',$s['action_scheduler_query']['reason_code']);self::assertSame('AVAILABLE',$s['action_scheduler_query']['state']);self::assertFalse($s['retry_permitted']);}
 public function testRendererSeparatesInspectedAvailabilityFromQueryFailure():void {
  $h=(new SchedulerDependencyPanel())->render(['action_scheduler_dependency'=>['state'=>'AVAILABLE','reason_code'=>'INSPECTION_PASSED','version'=>'3.9.3','registered_version'=>'3.9.3','minimum_version'=>'3.5.0','maximum_major_exclusive'=>5],'action_scheduler_query'=>['state'=>'UNAVAILABLE','reason_code'=>'QUERY_EXCEPTION']]);foreach(['INSPECTION_PASSED','QUERY_EXCEPTION','Task query readiness','3.9.3','does not establish task-query success']as $label)self::assertStringContainsString($label,$h);self::assertStringNotContainsString('not installed',$h);
  $h=(new SchedulerDependencyPanel())->render(['action_scheduler_dependency'=>['reason_code'=>'private-secret'],'action_scheduler_query'=>['reason_code'=>'<script>secret</script>']]);self::assertStringNotContainsString('private-secret',$h);self::assertStringNotContainsString('<script>',$h);self::assertStringContainsString('UNVERIFIED',$h);
 }
 public function testActualInstalledAutomationRouteRendersBothReasonFields():void {PortalAccessFixture::reset();PortalAccessFixture::enroll();$_GET=['df_view'=>'automation','df_shop'=>'all'];$m=new ReflectionMethod(DigiForge\Portal\Portal::class,'automationOperations');$m->setAccessible(true);ob_start();$m->invoke(new DigiForge\Portal\Portal());$h=ob_get_clean();foreach(['Dependency inspection reason','Task query readiness','Task query reason','Action Scheduler evidence','WP cron evidence']as $label)self::assertStringContainsString($label,$h);self::assertStringNotContainsString('unavailable or not installed',$h);$_GET=[];global $wpdb;$wpdb->query('COMMIT');PortalAccessFixture::reset();$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');}
}
