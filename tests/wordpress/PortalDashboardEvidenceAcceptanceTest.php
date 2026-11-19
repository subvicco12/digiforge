<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
use DigiForge\Database\Tables;
final class PortalDashboardEvidenceAcceptanceTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);}
 private function render(string $view,array $filters=[],string $shop='digital'):string {$old=$_GET;try{$_GET=['df_view'=>$view,'df_shop'=>$shop]+$filters;return (new DigiForge\Portal\Portal())->render();}finally{$_GET=$old;}}
 public function testGlobalApprovalCountDrillsIntoSelectedPendingEvidenceWithoutMakingADecision():void {
  global $wpdb;$table=Tables::research_candidates();$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE review_status='PENDING'");$repo=new DigiForge\Research\Repository();$own=$repo->createCandidate(['shop_key'=>'digital','title'=>'dashboard-own-'.wp_rand(),'summary'=>'Synthetic pending opportunity']);$foreign=$repo->createCandidate(['shop_key'=>'jewelry','title'=>'dashboard-foreign-'.wp_rand(),'summary'=>'Synthetic foreign pending opportunity']);self::assertNotWPError($own);self::assertNotWPError($foreign);
  $html=$this->render('dashboard');$directory=getenv('DF_PORTAL_FIXTURE_DIR');if(is_string($directory)&&$directory!=='')self::assertSame(strlen($html),file_put_contents($directory.'/portal-dashboard--digital.html',$html));self::assertStringContainsString('<span>Pending opportunity approvals</span><strong>'.($before+2).'</strong>',$html);self::assertStringContainsString('Dashboard counts remain authoritative global counts',$html);self::assertStringContainsString($own['title'],$html);self::assertStringNotContainsString($foreign['title'],$html);self::assertStringContainsString('df_view=approvals',$html);$queue=$this->render('approvals');self::assertStringContainsString($own['title'],$queue);self::assertStringNotContainsString($foreign['title'],$queue);foreach([$own,$foreign]as $r)self::assertSame('PENDING',$wpdb->get_var($wpdb->prepare("SELECT review_status FROM $table WHERE id=%d",$r['id'])));self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public function testMissingApprovalCountCannotAppearAsZeroOrClear():void {
  global $wpdb;$table=Tables::research_candidates();$filter=static fn(string $sql):string=>str_contains($sql,'FROM '.$table)?'SELECT * FROM no_such_dashboard_pending_evidence':$sql;$old=$wpdb->suppress_errors(true);add_filter('query',$filter);try{$html=$this->render('dashboard');self::assertStringContainsString('<span>Pending opportunity approvals</span><strong>UNAVAILABLE</strong>',$html);self::assertStringNotContainsString('<span>Pending opportunity approvals</span><strong>0</strong>',$html);}finally{remove_filter('query',$filter);$wpdb->suppress_errors($old);}
 }
 /** @dataProvider lifecycleAvailability */
 public function testFinanceAndAnalyticsPreserveLifecycleCountAvailability(string $view,bool $sourceFails):void {
  global $wpdb;$source='SELECT COUNT(*) FROM '.Tables::products();
  $fault=static fn(string $sql):string=>$sql===$source?($sourceFails?'SELECT * FROM digiforge_synthetic_absent_lifecycle_counts':'SELECT 7'):$sql;
  $previous=$wpdb->suppress_errors(true);add_filter('query',$fault);
  try{
   $html=$this->render($view);
   self::assertStringContainsString('<div><span>Developed products</span><b>'.($sourceFails?'UNAVAILABLE':'7').'</b></div>',$html);
   if($sourceFails)self::assertStringNotContainsString('<div><span>Developed products</span><b>0</b></div>',$html);
  }finally{remove_filter('query',$fault);$wpdb->suppress_errors($previous);}
  self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public static function lifecycleAvailability():array {return [['finance',false],['finance',true],['analytics',false],['analytics',true]];}
 public function testFinanceDeclarationConsumerIsReadOnlyAndShowsExplicitScope():void {
  global $wpdb;$tables=[Tables::finance_ledger(),Tables::finance_periods(),Tables::analytics_snapshots(),Tables::tax_classifications()];$before=[];
  foreach($tables as $table)$before[$table]=$wpdb->get_results('SELECT * FROM '.$table.' ORDER BY id',ARRAY_A);
  $calls=0;$http=static function($pre)use(&$calls){$calls++;return new WP_Error('offline_finance_panel','External requests forbidden.');};add_filter('pre_http_request',$http);
  try{foreach(['finance','analytics'] as $view){$html=$this->render($view);foreach(['Provisional finance declarations','Authenticity: UNKNOWN','Source completeness: UNKNOWN','name="df_finance_environment"','External execution authority: NO'] as $text)self::assertStringContainsString($text,$html);}}
  finally{remove_filter('pre_http_request',$http);}
  self::assertSame(0,$calls);foreach($tables as $table)self::assertSame($before[$table],$wpdb->get_results('SELECT * FROM '.$table.' ORDER BY id',ARRAY_A));
  self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public function testDeclaredFinanceAmountsRemainScopedByShopAndEnvironment():void {
  require_once __DIR__.'/FinanceDeclarationImportTest.php';
  $input=(new FinanceDeclarationImportTest())->fixture(['external_transaction_id'=>'portal-own','amount'=>'12.1111','base_amount'=>'12.1111']);
  $repository=new DigiForge\Finance\Repository();self::assertSame('personalized_pod',$input['scope']['shop_key']);self::assertIsArray($repository->importDeclaration($input));
  foreach(['digital','jewelry'] as $unsupported)self::assertWPError($repository->importDeclaration(array_replace_recursive($input,['external_transaction_id'=>'portal-foreign-'.$unsupported,'scope'=>['shop_key'=>$unsupported]])));
  ShopAccessFixture::authorizeActor(get_current_user_id(),DigiForge\Security\ShopAccessPolicy::canonicalShops(),['manage_digiforge','manage_digiforge_finance']);
  $filters=['df_finance_environment'=>'test','df_finance_start'=>'2026-10-01','df_finance_end'=>'2026-10-31','df_finance_currency'=>'USD'];
  $html=$this->render('finance',$filters,'personalized_pod');
  self::assertSame(1,preg_match('~<section class="df-panel" data-finance-declarations>.*?</section>~s',$html,$match));$panel=$match[0];
  self::assertStringContainsString('12.1111',$panel);self::assertStringContainsString('PROVISIONAL',$panel);self::assertStringContainsString('Authenticity: UNKNOWN',$panel);self::assertStringContainsString('Period approval authority: NO',$panel);
  foreach([['digital',$filters],['personalized_pod',array_replace($filters,['df_finance_environment'=>'sandbox'])]] as [$shop,$scope]){
   $html=$this->render('finance',$scope,$shop);self::assertSame(1,preg_match('~<section class="df-panel" data-finance-declarations>.*?</section>~s',$html,$match));self::assertStringContainsString('NO_DECLARATIONS',$match[0]);self::assertStringNotContainsString('12.1111',$match[0]);
  }
 }
 public function testFinanceDeclarationsPreserveGlobalAuthorizationAndInvalidFilterFailure():void {
  $html=$this->render('finance',['df_finance_environment'=>['test']]);
  self::assertStringContainsString('Invalid exact finance filters; this declaration report was not queried.',$html);
  ShopAccessFixture::reset();ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge','manage_digiforge_finance']);
  $html=$this->render('finance');self::assertStringContainsString('requires assignments for all four shops',$html);self::assertStringNotContainsString('data-finance-declarations',$html);
 }
 public function testRevokedFinanceAssignmentCannotReadDeclarationPanel():void {
  $actor=get_current_user_id();$repository=new DigiForge\Security\ShopAccessRepository();$state=$repository->read('jewelry');self::assertNotWPError($state);$revoked=false;
  foreach($state['grants'] as $id=>$grant)if($grant['actor_id']===$actor){
   wp_set_current_user($grant['grantor_id']);try{self::assertNotWPError($repository->revoke('jewelry',$id,wp_generate_uuid4(),$state['revision']));$revoked=true;}finally{wp_set_current_user($actor);}break;
  }
  self::assertTrue($revoked);$html=$this->render('finance');self::assertStringContainsString('requires assignments for all four shops',$html);self::assertStringNotContainsString('data-finance-declarations',$html);
 }
 public function testFinanceDeclarationReadFailureCannotRenderZeroAmounts():void {
  global $wpdb;$faulted=false;$table=Tables::finance_ledger();
  $fault=static function(string $sql)use(&$faulted,$table):string{if(preg_match('/^\s*SELECT\b/i',$sql)&&str_contains($sql,$table)){$faulted=true;return 'SELECT * FROM digiforge_synthetic_absent_finance_declarations';}return $sql;};
  $previous=$wpdb->suppress_errors(true);add_filter('query',$fault);
  try{$html=$this->render('finance');}finally{remove_filter('query',$fault);$wpdb->suppress_errors($previous);}
  self::assertTrue($faulted);self::assertSame(1,preg_match('~<section class="df-panel" data-finance-declarations>.*?</section>~s',$html,$match));self::assertStringContainsString('UNAVAILABLE',$match[0]);self::assertStringNotContainsString('0.0000',$match[0]);self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 /** @dataProvider legacyPeriodMetrics */
 public function testLegacyFinancePeriodMissingOrUntypedMetricsNeverBecomeZero(array $metrics,array $expected):void {
  $canonical=['environment'=>'test','period_start'=>'2026-09-01','period_end'=>'2026-09-30','base_currency'=>'USD','metrics'=>$metrics,'calculation_version'=>'v2'];
  global $wpdb;$now=current_time('mysql',true);
  self::assertSame(1,$wpdb->insert(Tables::finance_periods(),array_diff_key($canonical,['metrics'=>true])+['metrics'=>DigiForge\Finance\Validator::canonicalJson($metrics),'metrics_hash'=>DigiForge\Finance\Validator::hash($canonical),'state'=>'REVIEW_REQUIRED','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]));
  $html=$this->render('finance');self::assertSame(1,preg_match('~<tr><td>2026-09-01 — 2026-09-30</td><td>test</td><td>(.*?)</td><td>(.*?)</td><td>(.*?)</td>~s',$html,$match));
  self::assertSame($expected[0],$match[1]);self::assertSame($expected[1],$match[2]);self::assertSame($expected[2],$match[3]);self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public static function legacyPeriodMetrics():array {return ['missing'=>[['unresolved_reconciliation_count'=>0],['UNVERIFIED','UNVERIFIED','UNVERIFIED']],'numeric strings'=>[['gross_revenue'=>'0','net_operating_profit'=>'0','margin_percent'=>'0'],['UNVERIFIED','UNVERIFIED','UNVERIFIED']],'wrong scalar types'=>[['gross_revenue'=>false,'net_operating_profit'=>null,'margin_percent'=>[]],['UNVERIFIED','UNVERIFIED','UNVERIFIED']],'known zero'=>[['gross_revenue'=>0,'net_operating_profit'=>0.0,'margin_percent'=>0.0],['0.00 USD','0.00 USD','0.00%']],'independent valid fields'=>[['net_operating_profit'=>7.5,'margin_percent'=>12.5],['UNVERIFIED','7.50 USD','12.50%']]];}
}
