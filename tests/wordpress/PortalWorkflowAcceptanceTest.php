<?php
declare(strict_types=1);
/** Local behavioral acceptance; never submits operator actions or contacts providers. */
final class PortalWorkflowAcceptanceTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();}
 private function render(string $view,string $shop='digital'):string {$get=$_GET;try{$_GET=['df_view'=>$view,'df_shop'=>$shop];return (new DigiForge\Portal\Portal())->render();}finally{$_GET=$get;}}
 public function testAnonymousAndSubscriberCannotReadOperatorViews():void {
  wp_set_current_user(0);self::assertStringContainsString('sign-in required',$this->render('system'));self::assertStringNotContainsString('df-portal-shell',$this->render('ai_budget'));
  wp_set_current_user(self::factory()->user->create(['role'=>'subscriber']));self::assertStringContainsString('not authorized',$this->render('research'));self::assertStringNotContainsString('df-portal-shell',$this->render('attention'));
 }
 public function testLimitedOperatorCannotReadAiPolicyOrIntegrationSecrets():void {
  $id=self::factory()->user->create(['role'=>'subscriber']);$user=new WP_User($id);$user->add_cap('manage_digiforge');$user->add_cap('manage_digiforge_research');wp_set_current_user($id);
  $html=$this->render('ai_budget');self::assertStringNotContainsString('name="budget_run"',$html);self::assertStringNotContainsString('name="api_key"',$html);self::assertStringNotContainsString('name="template_cycle_limit"',$html);self::assertStringContainsString('<h1>Dashboard</h1>',$html);
  $html=$this->render('integrations');self::assertStringNotContainsString('name="api_key"',$html);self::assertStringContainsString('<h1>Dashboard</h1>',$html);
 }
 public function testAllNonFinanceViewsAndFourShopsRenderWithoutChangingSafetyOrBusinessState():void {
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));$portal=new DigiForge\Portal\Portal();$nav=(new ReflectionClass($portal))->getConstant('NAV');unset($nav['finance'],$nav['analytics']);$before=$this->controls();$mutations=[];$external=[];
  $observationTable=DigiForge\Database\Tables::pod_provenance_integrity_evidence();
  $query=static function(string $sql)use(&$mutations,$observationTable):string{
   // Existing anomaly observation auditing is intentional; preserve it while forbidding business/recovery mutations.
   $observationInsert=str_starts_with($sql,'INSERT INTO `'.$observationTable.'`');$observationRefresh=preg_match('/^UPDATE `'.preg_quote($observationTable,'/').'` SET `last_observed_at` = /',$sql)===1;
   if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i',$sql)&&!$observationInsert&&!$observationRefresh)$mutations[]=$sql;return $sql;
  };
  $http=static function($pre,$args,$url)use(&$external){$external[]=$url;return new WP_Error('offline_acceptance','All network blocked by local acceptance fixture.');};add_filter('query',$query);add_filter('pre_http_request',$http,10,3);
  try{foreach(['digital','personalized_pod','standard_pod','jewelry'] as $shop)foreach($nav as $view=>$item){$html=$this->render($view,$shop);self::assertStringContainsString('df-portal-shell',$html);self::assertStringContainsString('<h1>'.esc_html($item['label']).'</h1>',$html);self::assertStringContainsString('aria-label="DigiForge navigation"',$html);self::assertStringContainsString('aria-current="page"',$html);self::assertStringContainsString('STOP ALL: ON',$html);$directory=getenv('DF_PORTAL_FIXTURE_DIR');if(is_string($directory)&&$directory!==''){$target=$directory.'/portal-'.$view.'--'.$shop.'.html';self::assertSame(strlen($html),file_put_contents($target,$html));}}}finally{remove_filter('query',$query);remove_filter('pre_http_request',$http,10);}
  self::assertSame([],$mutations,'Rendering must not change business/recovery state or activate workflows; existing anomaly observation audit metadata is preserved.');self::assertSame([],$external);self::assertSame($before,$this->controls());
 }
 public function testAiPolicyAndUsageProjectionIsShopIsolated():void {
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));$repo=new DigiForge\AI\ShopAiGovernanceRepository();foreach(['digital','personalized_pod','standard_pod','jewelry'] as $shop)self::assertFalse(is_wp_error($repo->savePolicy(['shop_key'=>$shop,'currency'=>'USD','stages'=>['develop'=>['limit'=>8]]])));
  foreach(['digital','personalized_pod','standard_pod','jewelry'] as $shop){$data=(new DigiForge\Portal\OperationalDepthReadModel())->aiBudget($shop);self::assertSame('AVAILABLE',$data['policies_query_state']);self::assertCount(1,$data['policies']);self::assertSame($shop,$data['policies'][0]['shop_key']);self::assertFalse($data['external_execution_authorized']);}
 }
 public function testReadFailureIsVisibleAndRecoveryViewCannotExecute():void {
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));$table=DigiForge\Database\Tables::shop_ai_policies();$filter=static fn(string $sql):string=>str_contains($sql,'FROM '.$table)?str_replace($table,'digiforge_missing_policy_fixture',$sql):$sql;add_filter('query',$filter);try{$html=$this->render('ai_budget');}finally{remove_filter('query',$filter);}self::assertStringContainsString('AI policy or cost evidence unavailable',$html);self::assertStringContainsString('External execution authority: NO',$html);
  $html=$this->render('attention');self::assertStringContainsString('Visibility does not execute recovery',$html);self::assertStringNotContainsString('Execute restore',$html);self::assertStringNotContainsString('name="restore"',$html);self::assertSame(['stop_all'=>true,'externally_locked'=>true,'automation_armed'=>false],$this->controls());
 }
 private function controls():array {return ['stop_all'=>DigiForge\Core\Settings::get('stop_all',true),'externally_locked'=>DigiForge\Core\Settings::safety_locked(),'automation_armed'=>DigiForge\Core\Settings::get('automation_armed',false)];}
}
