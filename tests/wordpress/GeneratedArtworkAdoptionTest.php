<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/fixtures/ImagePanelFixture.php';
require_once __DIR__.'/fixtures/PhotoWorkflowFixtureIsolation.php';
use DigiForge\AI\{ImageGenerationExecution,ImageGenerationJournal};
use DigiForge\Research\{DesignFactoryRepository,DesignFactoryContract};

/** Real WP persistence; provider execution is exclusively the final offline transport. */
final class GeneratedArtworkAdoptionTest extends WP_UnitTestCase {
 use PhotoWorkflowFixtureIsolation {tearDown as private restoreFixture;}
 private array $extraFloors=[];
 protected function setUp():void {
  parent::setUp();DigiForge\Core\Activator::activate();ShopAccessFixture::reset();$this->capturePhotoFixtureState();global $wpdb;
  foreach(['ai_models','ai_tasks','integrations','integration_secrets']as $method){$t=DigiForge\Database\Tables::{$method}();$this->extraFloors[$t]=(int)$wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM '.$t);}
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge_ai','manage_digiforge_pod','manage_digiforge_research']);DigiForge\Core\Settings::protectProduction();$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
 }
 protected function tearDown():void {global $wpdb;foreach(array_reverse($this->extraFloors,true)as $t=>$floor)$wpdb->query('DELETE FROM '.$t.' WHERE id>'.(int)$floor);$this->restoreFixture();}
 private function fixture():array {$spec=ImagePanelFixture::specification();$policy=ImagePanelFixture::policy();return [$spec,ImagePanelFixture::candidate($spec,$policy)];}
 private function payload():array {return ['svg'=>file_get_contents(__DIR__.'/../fixtures/design-factory/mug-original.svg'),'raster_uploads'=>[],'rights_note'=>'SYNTHETIC human adaptation and font declaration; no commercial acceptance','source_note'=>'Synthetic editable source derived from the exact generated candidate'];}
 private function checked($v):array {self::assertNotWPError($v);self::assertIsArray($v);return $v;}
 public function testGeneratedCandidateToEditableHumanReviewAndImmutableRevision():void {
  [$spec,$job]=$this->fixture();$s=new ImageGenerationExecution();$p=$this->payload();$linked=$this->checked($s->linkDerivative('personalized_pod',$job['id'],$job['result']['sha256'],$p,true,$job['event_sha256']));
  self::assertSame('DERIVATIVE_LINKED',$linked['state']);$r=new DesignFactoryRepository();$v=$this->checked($r->read('personalized_pod',$linked['derivative_hash']));
  self::assertSame('GENERATED_RASTER_EDITABLE_DERIVATIVE',$v['record']['provenance']['method']);$origin=$v['record']['provenance']['generated_source'];self::assertSame('shop2-generated-derivative-v1',$origin['version']);self::assertSame($job['id'],$origin['image_job_id']);self::assertSame($job['result']['sha256'],$origin['raster_sha256']);self::assertSame($job['identity']['wire_contract']['request_sha256'],$origin['request_sha256']);self::assertSame($spec['hash'],$origin['specification_sha256']);self::assertSame('OFFLINE_SIMULATOR',$origin['mode']);self::assertFalse($origin['raster_is_editable']);self::assertNull($v['review_state']);
  self::assertSame($linked['derivative_hash'],$this->checked($s->linkDerivative('personalized_pod',$job['id'],$job['result']['sha256'],$p,true,$job['event_sha256']))['derivative_hash'],'Identical replay must return the same artifact, not import another version.');$changed=$p;$changed['source_note'].=' material change';self::assertWPError($s->linkDerivative('personalized_pod',$job['id'],$job['result']['sha256'],$changed,true,$job['event_sha256']));
  // Human artwork review needs research/POD authority, not the image-job creator's AI capability/session.
  $reviewer=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($reviewer);ShopAccessFixture::authorizeActor($reviewer,['personalized_pod'],['manage_digiforge_pod','manage_digiforge_research']);wp_get_current_user()->add_cap('manage_digiforge_ai',false);global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
  $approved=$this->checked($r->review('personalized_pod',$v['hash'],'APPROVE',array_fill_keys(DesignFactoryContract::REVIEW_CRITERIA,true),'Synthetic test decision, not genuine owner approval'));self::assertSame('HUMAN_APPROVED',$approved['review_state']);self::assertTrue(method_exists($r,'generatedRaster'),'Independent human review needs the exact generated source preview.');$png=$this->checked($r->generatedRaster('personalized_pod',$approved['hash']));self::assertSame($origin['raster_sha256'],hash('sha256',base64_decode($png['base64'])));self::assertFalse($png['editable']);$out=$this->checked($r->handoff('personalized_pod',$approved['hash']));self::assertSame($origin,$out['design_metadata']['generated_source']);self::assertFalse($out['design_metadata']['supplier_certified']);
  $revision=$this->checked($r->revise('personalized_pod',$approved['hash'],'Synthetic material change needs fresh artwork and review'));self::assertNull($revision['record']['asset']);self::assertNull($revision['review_state']);self::assertWPError($r->handoff('personalized_pod',$revision['hash']));self::assertSame('HUMAN_APPROVED',$this->checked($r->read('personalized_pod',$approved['hash']))['review_state']);self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public function testWrongInputScopeActorOrExpectedEventCannotConsumeAdoption():void {
  [$spec,$j]=$this->fixture();$s=new ImageGenerationExecution();$p=$this->payload();self::assertWPError($s->linkDerivative('personalized_pod',$j['id'],$j['result']['sha256'],$p,true,str_repeat('f',64)));self::assertWPError($s->linkDerivative('digital',$j['id'],$j['result']['sha256'],$p,true,$j['event_sha256']));self::assertWPError($s->linkDerivative('personalized_pod',$j['id'],str_repeat('f',64),$p,true,$j['event_sha256']));self::assertWPError($s->linkDerivative('personalized_pod',$j['id'],$j['result']['sha256'],$p,false,$j['event_sha256']));self::assertSame('SUCCEEDED',$this->checked($s->read('personalized_pod',$j['id']))['state']);
  $actor=get_current_user_id();wp_set_current_user(self::factory()->user->create(['role'=>'subscriber']));self::assertWPError($s->linkDerivative('personalized_pod',$j['id'],$j['result']['sha256'],$p,true,$j['event_sha256']));wp_set_current_user($actor);self::assertSame('SUCCEEDED',$this->checked($s->read('personalized_pod',$j['id']))['state']);
 }
 public function testUnconfirmedLinkCannotBeApprovedAndRecoveryNeverRepeatsImport():void {
  [$spec,$j]=$this->fixture();$s=new ImageGenerationExecution();$fault=static fn($q)=>str_contains($q,'INSERT INTO')&&str_contains($q,'digiforge_image_job_event_')&&str_contains($q,'DERIVATIVE_LINKED')?'SELECT missing_derivative_commit_ack':$q;add_filter('query',$fault);
  try{self::assertWPError($s->linkDerivative('personalized_pod',$j['id'],$j['result']['sha256'],$this->payload(),true,$j['event_sha256']));}finally{remove_filter('query',$fault);}
  $pending=$this->checked($s->read('personalized_pod',$j['id']));self::assertSame('ADOPTING',$pending['state']);$r=new DesignFactoryRepository();$versions=$this->checked($r->recent('personalized_pod',$spec['record']['commercial_hash']));$assets=array_values(array_filter($versions,static fn($v)=>$v['kind']==='ASSET_READY'));self::assertCount(1,$assets);self::assertWPError($r->review('personalized_pod',$assets[0]['hash'],'APPROVE',array_fill_keys(DesignFactoryContract::REVIEW_CRITERIA,true),'Must not approve an unlinked source'));self::assertSame('UNKNOWN',$this->checked($s->recover('personalized_pod',$j['id']))['state']);self::assertWPError($s->linkDerivative('personalized_pod',$j['id'],$j['result']['sha256'],$this->payload(),true,$j['event_sha256']));
 }
 public function testChangedGeneratedBytesInvalidateDerivedApprovalAdmission():void {
  [$spec,$j]=$this->fixture();$s=new ImageGenerationExecution();$linked=$this->checked($s->linkDerivative('personalized_pod',$j['id'],$j['result']['sha256'],$this->payload(),true,$j['event_sha256']));$path=DigiForge\ProductFactory\AssetStorage::absolutePath($j['result']['storage_reference']);$bytes=file_get_contents($path);
  try{file_put_contents($path,'corrupt');$r=new DesignFactoryRepository();self::assertWPError($r->read('personalized_pod',$linked['derivative_hash']));self::assertWPError($r->review('personalized_pod',$linked['derivative_hash'],'APPROVE',array_fill_keys(DesignFactoryContract::REVIEW_CRITERIA,true),'Corrupt source must stay held'));}finally{file_put_contents($path,$bytes);}
 }
 public function testManualHandoffRetainsExactHistoricalMetadataShape():void {
  [$spec]=$this->fixture();$r=new DesignFactoryRepository();$v=$this->checked($r->importArtwork('personalized_pod',$spec['hash'],$this->payload()));$approved=$this->checked($r->review('personalized_pod',$v['hash'],'APPROVE',array_fill_keys(DesignFactoryContract::REVIEW_CRITERIA,true),'Synthetic legacy-shape regression review'));$out=$this->checked($r->handoff('personalized_pod',$approved['hash']));self::assertArrayNotHasKey('generated_source',$out['design_metadata'],'Historical manual package readers compare exact canonical metadata; no null generated-origin field may be added.');
 }
 public function testHandoffLateSourceFailureCannotReturnSuccessfulEnvelope():void {
  [$spec,$j]=$this->fixture();$s=new ImageGenerationExecution();$linked=$this->checked($s->linkDerivative('personalized_pod',$j['id'],$j['result']['sha256'],$this->payload(),true,$j['event_sha256']));$r=new DesignFactoryRepository();$approved=$this->checked($r->review('personalized_pod',$linked['derivative_hash'],'APPROVE',array_fill_keys(DesignFactoryContract::REVIEW_CRITERIA,true),'Synthetic review for late admission denial'));$reads=0;$fault=static function($q)use(&$reads,$j){if(str_starts_with($q,'SELECT option_value')&&str_contains($q,'digiforge_image_job_head_'.$j['id'])&&++$reads===2)return 'SELECT missing_generated_handoff_source';return $q;};add_filter('query',$fault);
  try{self::assertWPError($r->handoff('personalized_pod',$approved['hash']),'Late lineage failure must deny the envelope, not embed WP_Error inside success metadata.');self::assertSame(2,$reads);}finally{remove_filter('query',$fault);}
 }

}
