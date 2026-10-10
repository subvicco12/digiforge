<?php
declare(strict_types=1);
final class AiReconciliationRetryVetoTest extends WP_UnitTestCase {
 public function testExplicitReconciliationVetoStopsRetryAndStructuralRestart():void {
  DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();$automation=new DigiForge\ProductFactory\ApprovalAutomation();$retry=new ReflectionMethod($automation,'retryStage');$restart=new ReflectionMethod($automation,'restartManifest');$cron=get_option('cron');$calls=0;$writes=[];$key='offline_retry_veto_'.wp_generate_uuid4();
  $http=static function()use(&$calls){$calls++;return new WP_Error('offline','No HTTP permitted.');};$query=static function(string $sql)use(&$writes):string{if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i',$sql))$writes[]=$sql;return $sql;};add_filter('pre_http_request',$http);add_filter('query',$query);
  try{foreach(['digiforge_launch_ai_incomplete','digiforge_launch_ai_invalid_json','digiforge_production_invalid_manifest'] as $code){$error=new WP_Error($code,'Reconciliation required.',['retry_permitted'=>false,'ai_usage_id'=>42]);self::assertFalse($retry->invoke($automation,1,'goods','offline-veto','develop',$error,$key,[],'development_poll_retries'));self::assertFalse($restart->invoke($automation,1,'goods','offline-veto',[],$error,$key,[],new DigiForge\ProductFactory\Orchestrator()));}}finally{remove_filter('pre_http_request',$http);remove_filter('query',$query);}
  self::assertSame(0,$calls);self::assertSame([],$writes);self::assertSame($cron,get_option('cron'));self::assertFalse(get_option($key,false));
 }
}
