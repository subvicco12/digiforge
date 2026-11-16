<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
use DigiForge\Research\DesignFactoryRepository;
use DigiForge\Research\CommercialResearchRepository;
use DigiForge\Portal\CommercialResearchPanel;
use DigiForge\Portal\DesignFactoryPanel;
/** Real WordPress processor/render tests; upload-positive HTTP coverage is separate. */
final class DesignFactoryPortalControlsTest extends WP_UnitTestCase
{
 private array $selected;
 private array $approved;
 protected function setUp():void {
  parent::setUp();$_FILES=[];$_GET=[];
  $id=self::factory()->user->create();$u=new WP_User($id);foreach(['manage_digiforge_pod','manage_digiforge_research']as $cap)$u->add_cap($cap);wp_set_current_user($id);
  DigiForge\Core\Activator::activate();ShopAccessFixture::reset();ShopAccessFixture::authorizeActor($id,['personalized_pod'],['manage_digiforge_pod','manage_digiforge_research']);
  $f=json_decode(file_get_contents(__DIR__.'/../fixtures/research/commercial-research.json'),true);$r=new CommercialResearchRepository();$a=$r->create($f['research']);$b=$r->importResponse('personalized_pod',$a['hash'],json_encode($f['response']),['method'=>'manual','provider'=>'human','model'=>'none','response_id'=>'controls-test','generated_at'=>'2026-11-03T12:00:00Z']);$this->selected=$r->select('personalized_pod',$b['hash'],1);
  $this->approved=(new DesignFactoryRepository())->approveConcept('personalized_pod',$this->selected['hash'],['sources_reviewed'=>true,'buyer_intent_reviewed'=>true,'gifting_occasion_reviewed'=>true,'note'=>'Synthetic controls source review']);
 }
 protected function tearDown():void {$_FILES=[];$_GET=[];parent::tearDown();}
 private function post(string $op,?string $hash=null):array {return ['operation'=>$op,'shop_key'=>'personalized_pod','workflow_hash'=>$this->selected['hash'],'factory_hash'=>$hash??$this->approved['hash']];}
 private function fields():array {return ['profile'=>'fl03_mug_11oz','layout_notes'=>'Original paired paths around native names','typography'=>'Licensed native serif text','font_license_reference'=>'Synthetic license record','palette'=>'#123456, #ffffff','asset_requirements'=>"Original fixed paths\nSeparate native text",'native_regions'=>[['name'=>'names','kind'=>'text','x'=>'500','y'=>'400','width'=>'1400','height'=>'300']]];}
 private function prepare():array {$v=(new CommercialResearchPanel())->process($this->post('design_prepare')+$this->fields());self::assertIsArray($v);return $v;}
 private function html(string $hash,bool $manualOnly=false):string {$_GET['df_factory_version']=$hash;ob_start();(new DesignFactoryPanel())->render('personalized_pod',$this->selected['hash'],null,$manualOnly);return ob_get_clean();}
 public function testStructuredRowsCreateExactImmutableSpecificationWithoutJson():void {$v=$this->prepare();$r=$v['design_factory']['record'];self::assertSame('SPEC_READY',$r['kind']);self::assertSame($this->approved['hash'],$r['parent_hash']);self::assertSame([['name'=>'names','kind'=>'text','x'=>500,'y'=>400,'width'=>1400,'height'=>300]],$r['specification']['layout']['native_regions']);self::assertSame('CONCEPT_APPROVED',(new DesignFactoryRepository())->read('personalized_pod',$this->approved['hash'])['record']['kind']);}
 public function testNormalFormsExposeRowsUploadsAndStructuredRevisionWithoutRawJson():void {
  $html=$this->html($this->approved['hash']);self::assertStringContainsString('name="native_regions[0][name]"',$html);self::assertStringNotContainsString('name="native_regions_json"',$html);self::assertStringContainsString('inputmode="numeric"',$html);
  $v=$this->prepare();$html=$this->html($v['design_factory_hash']);self::assertStringContainsString('enctype="multipart/form-data"',$html);self::assertStringContainsString('name="artwork_svg"',$html);self::assertStringContainsString('name="artwork_pngs[]"',$html);self::assertStringNotContainsString('name="rasters_json"',$html);self::assertStringNotContainsString('name="choices_json"',$html);self::assertStringContainsString('name="revise_layout"',$html);self::assertStringContainsString('name="_wpnonce"',$html);self::assertStringContainsString('value="digiforge_commercial_research"',$html);
  $dir=getenv('PORTAL_CONTROLS_EVIDENCE');if($dir){file_put_contents($dir.'/specification.html',$this->html($this->approved['hash']));file_put_contents($dir.'/artwork.html',$html);}
 }
 public function testMalformedRegionCoordinatesAndCountsFailWithoutNewVersions():void {
  $cases=['-1','1.2','1e2','1tail','999999999999999999999',[],true];$panel=new CommercialResearchPanel();$before=count((new DesignFactoryRepository())->recent('personalized_pod',$this->selected['hash']));
  foreach($cases as $bad){$fields=$this->fields();$fields['native_regions'][0]['x']=$bad;self::assertWPError($panel->process($this->post('design_prepare')+$fields));}
  foreach([[],array_fill(0,11,$this->fields()['native_regions'][0])]as $bad){$fields=$this->fields();$fields['native_regions']=$bad;self::assertWPError($panel->process($this->post('design_prepare')+$fields));}
  self::assertCount($before,(new DesignFactoryRepository())->recent('personalized_pod',$this->selected['hash']));
 }
 public function testStructuredRevisionPreservesParentAndUnchangedOriginal():void {
  $v=$this->prepare();$fields=$this->fields();$fields['layout_notes']='Revised original layout';$result=(new CommercialResearchPanel())->process($this->post('design_revise',$v['design_factory_hash'])+$fields+['note'=>'Move fixed paths','revise_layout'=>'1']);self::assertIsArray($result);self::assertSame($v['design_factory_hash'],$result['design_factory']['record']['parent_hash']);self::assertSame('Revised original layout',$result['design_factory']['record']['specification']['layout']['layout_notes']);self::assertSame('Original paired paths around native names',(new DesignFactoryRepository())->read('personalized_pod',$v['design_factory_hash'])['record']['specification']['layout']['layout_notes']);
 }
 public function testForgedUploadMetadataFailsClosedWithoutReadingLocalPath():void {
  $v=$this->prepare();$post=$this->post('design_import',$v['design_factory_hash'])+['rights_note'=>'Synthetic original rights','source_note'=>'Synthetic upload'];
  $_FILES['artwork_svg']=['name'=>'original.svg','type'=>'image/svg+xml','tmp_name'=>__DIR__.'/../fixtures/design-factory/mug-original.svg','error'=>UPLOAD_ERR_OK,'size'=>100];self::assertWPError((new CommercialResearchPanel())->process($post));
  $_FILES['artwork_svg']['error']=UPLOAD_ERR_PARTIAL;self::assertWPError((new CommercialResearchPanel())->process($post));
  self::assertNull((new DesignFactoryRepository())->read('personalized_pod',$v['design_factory_hash'])['record']['asset']);
 }
 public function testExactCommercialAndActorAndManualMountRemainRequired():void {
  $v=$this->prepare();$post=$this->post('design_revise',$v['design_factory_hash'])+['note'=>'Cross concept'];$post['workflow_hash']=str_repeat('a',64);self::assertWPError((new CommercialResearchPanel())->process($post));$post['shop_key']='digital';self::assertWPError((new CommercialResearchPanel())->process($post));
  $html=$this->html($v['design_factory_hash'],true);self::assertStringNotContainsString('value="design_generate"',$html);self::assertStringNotContainsString('value="design_image_',$html);wp_set_current_user(0);self::assertWPError((new CommercialResearchPanel())->process($this->post('design_prepare')+$this->fields()));
 }
}
