<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
use DigiForge\Portal\ScopedCapabilityPolicyRepository;
use DigiForge\Database\Tables;
final class PortalScopedPolicyAcceptanceTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);}
 private function render(string $shop):string {$old=$_GET;try{$_GET=['df_view'=>'settings','df_shop'=>$shop];return (new DigiForge\Portal\Portal())->render();}finally{$_GET=$old;}}
 public function testCurrentAndHistoricalPolicyEvidenceIsScopedAndNeverAuthorizesExecution():void {
  $repo=new ScopedCapabilityPolicyRepository();$key='workflow_'.wp_rand();$a=$repo->append('digital',$key,'research',false);$b=$repo->append('digital',$key,'research',true);$other=$repo->append('jewelry','foreign_'.wp_rand(),'research',true);foreach([$a,$b,$other]as $r)self::assertNotWPError($r);
  $html=$this->render('digital');self::assertStringContainsString($key,$html);self::assertStringContainsString($a['policy_hash'],$html);self::assertStringContainsString($b['policy_hash'],$html);self::assertStringNotContainsString($other['workflow_key'],$html);self::assertStringContainsString('Policy execution authority',$html);self::assertStringContainsString('STOP ALL',$html);self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
 }
 public function testTamperedPolicyIsUnavailableAndCannotBecomeAnAppendPredecessor():void {
  global $wpdb;$repo=new ScopedCapabilityPolicyRepository();$key='tamper_'.wp_rand();$r=$repo->append('digital',$key,'research',false);self::assertNotWPError($r);$wpdb->update(Tables::scoped_capability_policies(),['enabled'=>1],['id'=>$r['id']]);$state=null;self::assertNull($repo->latest('digital',$key,'research',$state));self::assertSame('UNAVAILABLE',$state);self::assertWPError($repo->append('digital',$key,'research',false));self::assertStringContainsString('Scoped policy evidence unavailable',$this->render('digital'));
 }
 public function testTamperedEarlierRevisionInvalidatesLatestChain():void {
  global $wpdb;$repo=new ScopedCapabilityPolicyRepository();$key='chain_'.wp_rand();$first=$repo->append('digital',$key,'research',false);$next=$repo->append('digital',$key,'research',true);self::assertNotWPError($first);self::assertNotWPError($next);$wpdb->update(Tables::scoped_capability_policies(),['enabled'=>1],['id'=>$first['id']]);$state=null;self::assertNull($repo->latest('digital',$key,'research',$state));self::assertSame('UNAVAILABLE',$state);self::assertWPError($repo->append('digital',$key,'research',false));
 }
 public function testReadFailureIsUnavailableInsteadOfZeroPolicies():void {
  $table=Tables::scoped_capability_policies();$filter=static fn(string $sql):string=>str_contains($sql,'FROM '.$table)?'SELECT * FROM no_such_scoped_policy_acceptance':$sql;add_filter('query',$filter);try{self::assertStringContainsString('Scoped policy evidence unavailable',$this->render('digital'));}finally{remove_filter('query',$filter);}
 }
 public function testGlobalPolicyHistoryExcludesUnassignedLegacyShopsBeforeItsWindow():void {
  $repo=new ScopedCapabilityPolicyRepository();$own=$repo->append('digital','global_owned_'.wp_rand(),'research',false);self::assertNotWPError($own);global $wpdb;$unknown=['shop_key'=>'unassigned_shop','workflow_key'=>'unknown_private_'.wp_rand(),'capability'=>'research','enabled'=>false,'policy_version'=>1,'previous_policy_hash'=>''];$canonical=wp_json_encode($unknown);$unknown['policy_hash']=hash('sha256',$canonical);$unknown['enabled']=0;$unknown['created_by']=get_current_user_id();$unknown['created_at']=current_time('mysql',true);self::assertSame(1,$wpdb->insert(Tables::scoped_capability_policies(),$unknown));$html=$this->render('all');self::assertStringContainsString($own['workflow_key'],$html);self::assertStringNotContainsString($unknown['workflow_key'],$html);$state=null;$rows=$repo->recent('','',1,$state);self::assertSame('AVAILABLE',$state);self::assertCount(1,$rows);self::assertSame($own['policy_hash'],$rows[0]['policy_hash']);
 }
 public function testUnassignedAnonymousForeignAndUnknownWritersCannotReadOrAppendPolicyEvidence():void {
  $repo=new ScopedCapabilityPolicyRepository();$assigned=get_current_user_id();$unassigned=self::factory()->user->create(['role'=>'administrator']);$reads=0;$writes=0;$table=Tables::scoped_capability_policies();$capture=static function(string $sql)use(&$reads,&$writes,$table):string{if(str_contains($sql,$table)){if(str_starts_with($sql,'SELECT'))++$reads;if(str_starts_with($sql,'INSERT'))++$writes;}return $sql;};add_filter('query',$capture);
  try{foreach([[0,'digital'],[$unassigned,'digital'],[$assigned,'unknown_shop']]as [$actor,$shop]){wp_set_current_user($actor);self::assertWPError($repo->append($shop,'denied_'.wp_rand(),'research',true));}wp_set_current_user($assigned);ShopAccessFixture::reset();ShopAccessFixture::authorizeActor($assigned,['digital'],['manage_digiforge']);self::assertWPError($repo->append('jewelry','foreign_'.wp_rand(),'research',true));self::assertSame(0,$reads);self::assertSame(0,$writes);}finally{remove_filter('query',$capture);wp_set_current_user($assigned);}self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }

}
