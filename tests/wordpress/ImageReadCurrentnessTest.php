<?php
declare(strict_types=1);
require_once __DIR__.'/ImageTaskReconciliationTest.php';
use DigiForge\AI\{ImageGenerationExecution,ImageGenerationJournal,ImageGenerationAccess};
use DigiForge\Database\Tables;

/** Actual job evidence remains private if admission changes while it is loaded. */
final class ImageReadCurrentnessTest extends WP_UnitTestCase {
 use PhotoWorkflowFixtureIsolation {tearDown as private restoreFixture;}
 private array $floors=[];
 private int $requests=0;
 protected function setUp():void {
  parent::setUp();DigiForge\Core\Activator::activate();ShopAccessFixture::reset();$this->capturePhotoFixtureState();global $wpdb;
  foreach(['jobs','ai_models','ai_tasks','integrations','integration_secrets','research_candidates','research_reviews','opportunities','product_families','products','product_versions']as $method){$table=Tables::{$method}();$this->floors[$table]=(int)$wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM '.$table);}
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge_pod','manage_digiforge_research','manage_digiforge_ai']);$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');DigiForge\Core\Settings::protectProduction();add_filter('pre_http_request',[$this,'denyHttp'],10,3);
 }
 public function denyHttp($pre,$args,$url):WP_Error {$this->requests++;return new WP_Error('offline_test_http_denied');}
 protected function tearDown():void {remove_filter('pre_http_request',[$this,'denyHttp'],10);global $wpdb;foreach(array_reverse($this->floors,true)as $table=>$floor)$wpdb->query('DELETE FROM '.$table.' WHERE id>'.(int)$floor);$this->restoreFixture();}
 private function fixture():array {
  $policy=ImagePanelFixture::policy();$spec=ImagePanelFixture::specification();$service=new ImageGenerationExecution();$job=$service->prepareDesign('personalized_pod',$spec['hash'],$policy['hash'],['run_id'=>'image-read-'.wp_generate_uuid4(),'started_at'=>gmdate('Y-m-d H:i:s')]);self::assertNotWPError($job);return [$service,$job];
 }
 private function duringHeadRead(array $job,callable $change,callable $read):mixed {
  $fired=false;$hook=function(string $sql)use($job,$change,&$fired):string{if(!$fired&&str_contains($sql,"option_name='digiforge_image_job_head_".$job['id']."'")){$fired=true;$change();}return $sql;};
  add_filter('query',$hook);try{$result=$read();}finally{remove_filter('query',$hook);}self::assertTrue($fired,'Change must occur during the actual persisted head load.');self::assertSame(0,$this->requests);return $result;
 }
 public function testCurrentActorReadsIdenticalImmutablePreparedEvidence():void {
  [$service,$job]=$this->fixture();$read=(new ImageGenerationJournal())->read($job['id']);self::assertNotWPError($read);self::assertSame($job['event_sha256'],$read['event_sha256']);self::assertSame('PREPARED',$read['state']);self::assertSame(0,$read['usage_id']);$discovery=(new ImageGenerationJournal())->forReconciliation();self::assertNotWPError($discovery);self::assertSame([$job['id']],array_column($discovery,'id'));self::assertSame($job['event_sha256'],$service->read('personalized_pod',$job['id'])['event_sha256']);self::assertSame(0,$this->requests);
 }
 public function testJournalReadDeniesRevocationDuringHeadLoad():void {
  [$service,$job]=$this->fixture();$actor=get_current_user_id();$result=$this->duringHeadRead($job,static fn()=>(new WP_User($actor))->add_cap('manage_digiforge_ai',false),fn()=>(new ImageGenerationJournal())->read($job['id']));self::assertWPError(ImageGenerationAccess::authorize());self::assertWPError($result);
 }
 public function testExecutionReadCannotExposeTheRevokedJournalRecord():void {
  [$service,$job]=$this->fixture();$actor=get_current_user_id();$result=$this->duringHeadRead($job,static fn()=>(new WP_User($actor))->add_cap('manage_digiforge_ai',false),fn()=>$service->read('personalized_pod',$job['id']));self::assertWPError(ImageGenerationAccess::authorize());self::assertWPError($result);
 }
 public function testDiscoveryRejectsAReplacementActorEvenWhenBothAreAuthorized():void {
  [$service,$job]=$this->fixture();$other=self::factory()->user->create(['role'=>'administrator']);ShopAccessFixture::authorizeActor($other,['personalized_pod'],['manage_digiforge_pod','manage_digiforge_research','manage_digiforge_ai']);global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');$result=$this->duringHeadRead($job,static fn()=>wp_set_current_user($other),fn()=>(new ImageGenerationJournal())->forReconciliation());self::assertSame($other,get_current_user_id());self::assertTrue(ImageGenerationAccess::authorize());self::assertWPError($result);
 }
 private function duringSourceRead(array $job,callable $change,callable $read):mixed {
  $fired=false;$hook=function(string $sql)use($job,$change,&$fired):string{if(!$fired&&str_contains($sql,"BINARY 'digiforge_design_personalized_pod_version_".$job['identity']['source_key']."'")){$fired=true;$change();}return $sql;};
  add_filter('query',$hook);try{$result=$read();}finally{remove_filter('query',$hook);}self::assertTrue($fired,'Change must occur during the later real design-source read.');self::assertSame(0,$this->requests);return $result;
 }
 public function testExecutionReadRejectsRevocationDuringItsLaterSourceRead():void {
  [$service,$job]=$this->fixture();$actor=get_current_user_id();$result=$this->duringSourceRead($job,static fn()=>(new WP_User($actor))->add_cap('manage_digiforge_ai',false),fn()=>$service->read('personalized_pod',$job['id']));self::assertWPError(ImageGenerationAccess::authorize());self::assertWPError($result);
 }
 public function testExecutionReadRejectsReplacementActorDuringItsLaterSourceRead():void {
  [$service,$job]=$this->fixture();$other=self::factory()->user->create(['role'=>'administrator']);ShopAccessFixture::authorizeActor($other,['personalized_pod'],['manage_digiforge_pod','manage_digiforge_research','manage_digiforge_ai']);global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');$result=$this->duringSourceRead($job,static fn()=>wp_set_current_user($other),fn()=>$service->read('personalized_pod',$job['id']));self::assertSame($other,get_current_user_id());self::assertTrue(ImageGenerationAccess::authorize());self::assertWPError($result);
 }

}
