<?php
declare(strict_types=1);
require_once __DIR__.'/ResearchExecutionTest.php';
use DigiForge\Database\Tables;
use DigiForge\Portal\ResearchExecutionPanel;
use DigiForge\Research\ResearchExecutionJournal;

/** Actual persisted job reads must not outlive current signed actor admission. */
final class ResearchReadCurrentnessTest extends WP_UnitTestCase {
 use PhotoWorkflowFixtureIsolation {tearDown as private restoreFixture;}
 private array $floors=[];
 private int $requests=0;
 protected function setUp():void {
  parent::setUp();DigiForge\Core\Activator::activate();ShopAccessFixture::reset();$this->capturePhotoFixtureState();global $wpdb;
  foreach(['jobs','ai_tasks','ai_models']as $method){$table=Tables::{$method}();$this->floors[$table]=(int)$wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM '.$table);}
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge_pod','manage_digiforge_research','manage_digiforge_ai']);DigiForge\Core\Settings::protectProduction();$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');add_filter('pre_http_request',[$this,'denyHttp'],10,3);
 }
 public function denyHttp($pre,$args,$url):WP_Error {$this->requests++;return new WP_Error('offline_test_http_denied');}
 protected function tearDown():void {
  remove_filter('pre_http_request',[$this,'denyHttp'],10);global $wpdb;foreach(array_reverse($this->floors,true)as $table=>$floor)$wpdb->query('DELETE FROM '.$table.' WHERE id>'.(int)$floor);$this->restoreFixture();
 }
 private function fixture():array {
  [$service,$source,$transport,$run]=(new ReflectionMethod(ResearchExecutionTest::class,'boot'))->invoke(new ResearchExecutionTest());
  $job=$service->enqueue('personalized_pod',[$source['hash']],['version'=>'shop2-research-run-v1','max_opportunities'=>1,'freshness_days'=>30,'per_task_ceiling'=>'0.020000','currency'=>'USD'],$run,'read-currentness-'.wp_generate_uuid4());self::assertNotWPError($job);return [$service,$job,$transport];
 }
 /** The real capability row changes during a real DB query, after initial admission. */
 private function revokeDuring(callable $operation,int $rootRead=1):mixed {
  $actor=get_current_user_id();$seen=0;$fired=false;
  $hook=function(string $sql)use($actor,$rootRead,&$seen,&$fired):string {
   if(!$fired&&str_contains($sql,'SELECT * FROM '.Tables::jobs().' WHERE id=')&&++$seen===$rootRead){$fired=true;(new WP_User($actor))->add_cap('manage_digiforge_ai',false);}
   return $sql;
  };
  add_filter('query',$hook);try{$result=$operation();}finally{remove_filter('query',$hook);}
  self::assertTrue($fired,'Revocation must occur inside the persisted read.');self::assertFalse((new WP_User($actor))->has_cap('manage_digiforge_ai'));self::assertWPError(DigiForge\Security\ShopAccess::authorize('personalized_pod','manage_digiforge_ai'));self::assertSame(0,$this->requests);return $result;
 }
 public function testCurrentActorCanReadAndRenderTheSameImmutableQueuedJob():void {
  [$service,$job,$transport]=$this->fixture();$journal=new ResearchExecutionJournal();$read=$journal->read($job['id']);self::assertNotWPError($read);self::assertSame($job['event_sha256'],$read['event_sha256']);$recent=$journal->recent();self::assertNotWPError($recent);self::assertSame([$job['id']],array_column($recent,'id'));
  ob_start();(new ResearchExecutionPanel($service))->render('personalized_pod');$html=ob_get_clean();self::assertStringContainsString($job['event_sha256'],$html);self::assertStringContainsString('research_queue_step',$html);self::assertSame(0,$transport->starts());self::assertSame(0,$this->requests);
 }
 public function testReadDeniesRevocationDuringPersistedLoad():void {
  [$service,$job]=$this->fixture();self::assertWPError($this->revokeDuring(fn()=>(new ResearchExecutionJournal())->read($job['id'])));
 }
 public function testRecentDeniesRevocationDuringPersistedLoad():void {
  $this->fixture();self::assertWPError($this->revokeDuring(fn()=>(new ResearchExecutionJournal())->recent()));
 }
 public function testPanelDiscardsAlreadyRenderedJobControlsAfterLateRevocation():void {
  [$service,$job]=$this->fixture();$html=$this->revokeDuring(function()use($service){ob_start();try{(new ResearchExecutionPanel($service))->render('personalized_pod');return ob_get_contents();}finally{ob_end_clean();}},2);
  self::assertStringNotContainsString($job['event_sha256'],$html);self::assertStringNotContainsString('research_queue_step',$html);self::assertStringNotContainsString('name="sources[]"',$html);self::assertStringContainsString('authorization',$html);
 }
 public function testActorReplacementDuringIndexLoadCannotReturnOriginalActorJobs():void {
  [$service,$job]=$this->fixture();$other=self::factory()->user->create(['role'=>'administrator']);ShopAccessFixture::authorizeActor($other,['personalized_pod'],['manage_digiforge_pod','manage_digiforge_research','manage_digiforge_ai']);global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');$switched=false;
  $hook=function(string $sql)use($other,&$switched):string{if(!$switched&&str_contains($sql,'SELECT * FROM '.Tables::jobs().' WHERE id=')){$switched=true;wp_set_current_user($other);}return $sql;};
  add_filter('query',$hook);try{$result=(new ResearchExecutionJournal())->recent();}finally{remove_filter('query',$hook);}
  self::assertTrue($switched);self::assertSame($other,get_current_user_id());self::assertTrue(ResearchExecutionJournal::access('personalized_pod'));self::assertWPError($result);self::assertSame('research_execution_actor_changed',$result->get_error_code());self::assertSame(0,$this->requests);
 }
 public function testForeignActorAndDigitalScopeCannotSeePersistedResearch():void {
  [$service,$job]=$this->fixture();$other=self::factory()->user->create(['role'=>'administrator']);ShopAccessFixture::authorizeActor($other,['personalized_pod'],['manage_digiforge_pod','manage_digiforge_research','manage_digiforge_ai']);global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');wp_set_current_user($other);self::assertWPError($service->read('personalized_pod',$job['id']));self::assertSame([],(new ResearchExecutionJournal())->recent());self::assertWPError($service->read('digital',$job['id']));self::assertSame(0,$this->requests);
 }

}
