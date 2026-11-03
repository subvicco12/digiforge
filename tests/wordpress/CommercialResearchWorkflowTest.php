<?php
declare(strict_types=1);
use DigiForge\Research\CommercialResearchRepository as Repo;
final class CommercialResearchWorkflowTest extends WP_UnitTestCase
{
 private function fixture():array {return json_decode(file_get_contents(__DIR__.'/../fixtures/research/commercial-research.json'),true);}
 protected function setUp():void {parent::setUp();self::assertTrue(class_exists(Repo::class),'Staged research repository required');$id=self::factory()->user->create();$u=new WP_User($id);foreach(['manage_digiforge_pod','manage_digiforge_research']as $cap)$u->add_cap($cap);wp_set_current_user($id);}
 public function testDeduplicatedResearchPreservesSourcesAndActor():void {
  $f=$this->fixture();$repo=new Repo();$a=$repo->create($f['research']);self::assertIsArray($a);$b=$repo->create($f['research']);self::assertSame($a,$b);self::assertSame(get_current_user_id(),$a['record']['created_by']);self::assertSame('RESEARCH_READY',$a['record']['stage']);self::assertSame('2026-11-03T12:00:00Z',$a['record']['research']['sources'][0]['observed_at']);
 }
 public function testSelectionArtworkApprovalRevisionAndReuse():void {
  $f=$this->fixture();$repo=new Repo();$a=$repo->create($f['research']);
  $b=$repo->importResponse('personalized_pod',$a['hash'],json_encode($f['response']),['method'=>'manual','provider'=>'human','model'=>'none','response_id'=>'authored','generated_at'=>'2026-11-03T12:00:00Z']);self::assertIsArray($b);self::assertSame('CONCEPTS_READY',$b['record']['stage']);
  $c=$repo->select('personalized_pod',$b['hash'],2);self::assertSame(2,$c['record']['selected_concept']);self::assertStringContainsString('Paired botanical initials',$c['record']['design_prompt']);
  $art=['svg'=>'<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><path d="M10 10 L90 90" stroke="#123456"/></svg>','width_px'=>100,'height_px'=>100,'dpi'=>300,'native_layers'=>['initials','year'],'rights_note'=>'Original geometry, licensed text reviewed','safe_area_reviewed'=>true,'font_license_reviewed'=>true,'mockup_reviewed'=>true];
  self::assertWPError($repo->attach('personalized_pod',$c['hash'],$art,['originality_reviewed'=>false,'licensing_reviewed'=>true,'note'=>'Needs review']));
  $d=$repo->attach('personalized_pod',$c['hash'],$art,['originality_reviewed'=>true,'licensing_reviewed'=>true,'note'=>'Checked original paths and font rights']);self::assertIsArray($d);self::assertSame('ARTWORK_READY',$d['record']['stage']);
  $e=$repo->decide('personalized_pod',$d['hash'],'REJECT','Revise the composition');self::assertSame('HUMAN_REJECTED',$e['artwork']['approval']['state']);self::assertWPError($repo->decide('personalized_pod',$d['hash'],'APPROVE','Changed my mind'));
  $r=$repo->revise('personalized_pod',$d['hash'],'Remove diagonal motif');self::assertNull($r['record']['artwork_version_hash']);self::assertSame('RESEARCH_READY',$r['record']['stage']);self::assertCount(5,$repo->history('personalized_pod',$r['hash']));
  $reuse=$repo->reuse('personalized_pod',$b['hash'],'poster','Anniversary botanical poster');self::assertSame('poster',$reuse['record']['research']['product_family']);self::assertNull($reuse['record']['selected_concept']);self::assertSame($b['hash'],$reuse['record']['reused_from']);self::assertSame('CONCEPTS_READY',$reuse['record']['stage']);self::assertSame($b['record']['response'],$reuse['record']['response']);
 }
 public function testIsolationAndUnauthorizedApprovals():void {
  $f=$this->fixture();$repo=new Repo();$a=$repo->create($f['research']);
  foreach(['digital','all','standard_pod','jewelry']as $shop){self::assertWPError($repo->read($shop,$a['hash']));self::assertWPError($repo->select($shop,$a['hash'],1));self::assertWPError($repo->decide($shop,$a['hash'],'APPROVE','No'));
  }
  $bad=$f['research'];$bad['sources']=[];self::assertWPError($repo->create($bad));wp_set_current_user(0);self::assertWPError($repo->read('personalized_pod',$a['hash']));self::assertWPError($repo->create($f['research']));self::assertWPError($repo->decide('personalized_pod',$a['hash'],'APPROVE','No'));
 }
 public function testStopAllPreventsAnyProviderOrHttpDispatch():void {
  $calls=0;$deny=static function()use(&$calls){++$calls;return new WP_Error('test_http','Denied');};add_filter('pre_http_request',$deny);
  try {$repo=new Repo();$a=$repo->create($this->fixture()['research']);$before=\DigiForge\Core\Settings::get('stop_all',true);$result=$repo->assist('personalized_pod',$a['hash']);self::assertWPError($result);self::assertSame(0,$calls);self::assertSame($before,\DigiForge\Core\Settings::get('stop_all',true));self::assertSame('RESEARCH_READY',$repo->read('personalized_pod',$a['hash'])['record']['stage']);}finally{remove_filter('pre_http_request',$deny);}
 }
 public function testPortalHasStructuredResearchAndSafeStages():void {
  self::assertTrue(class_exists(\DigiForge\Portal\CommercialResearchPanel::class),'Operator panel required');
  $panel=new \DigiForge\Portal\CommercialResearchPanel();ob_start();$panel->render('personalized_pod');$html=ob_get_clean();self::assertStringContainsString('Etsy competitor',$html);self::assertStringContainsString('Google trend',$html);self::assertStringContainsString('name="title"',$html);
  $v=$panel->process(['operation'=>'research','shop_key'=>'personalized_pod']+$this->fixture()['research']);self::assertIsArray($v);
  $_GET['df_research_version']=$v['hash'];ob_start();$panel->render('personalized_pod');$html=ob_get_clean();unset($_GET['df_research_version']);self::assertStringContainsString('UNTRUSTED SOURCE DATA',$html);self::assertStringContainsString('Import concepts',$html);self::assertStringContainsString('STOP_ALL_OR_UNAUTHORIZED',$html);
  self::assertWPError($panel->process(['operation'=>'approve','shop_key'=>'personalized_pod','workflow_hash'=>$v['hash']]));
 }
 private function enableOnlyInTestDatabase():void {
  \DigiForge\Core\Settings::ensure_defaults();global $wpdb;foreach(['activation_authorized','automation_armed','research','ai','research_activation_authorized','ai_activation_authorized']as $key)$wpdb->update(\DigiForge\Database\Tables::settings(),['setting_value'=>'true'],['setting_key'=>$key]);
  $wpdb->update(\DigiForge\Database\Tables::settings(),['setting_value'=>'false'],['setting_key'=>'stop_all']);wp_get_current_user()->add_cap('manage_digiforge_ai');
 }
 public function testUnavailableCredentialsFailBeforeHttp():void {
  $this->enableOnlyInTestDatabase();$provider=new \DigiForge\Research\GovernedResearchProvider();self::assertSame('CREDENTIALS_UNAVAILABLE',$provider->status()['reason']);self::assertWPError($provider->generate($this->fixture()['research'],str_repeat('a',64)));
 }
 public function testProviderFailureIsSingleAttemptAndNoInventedConcepts():void {
  $this->enableOnlyInTestDatabase();
  $provider=new class implements \DigiForge\Research\ResearchProvider {
   public int $calls=0;public function status():array{return ['available'=>true];}
   public function generate(array $research,string $attemptKey):array|WP_Error {++$this->calls;throw new RuntimeException('Provider failed');}
  };
  $repo=new Repo($provider);$a=$repo->create($this->fixture()['research']);self::assertWPError($repo->assist('personalized_pod',$a['hash']));self::assertWPError($repo->assist('personalized_pod',$a['hash']));self::assertSame(1,$provider->calls);self::assertNull($repo->read('personalized_pod',$a['hash'])['record']['response']);
 }
 public function testMalformedProviderResponseAndSpoofedImportProvenanceDenied():void {
  $this->enableOnlyInTestDatabase();$f=$this->fixture();
  $provider=new class implements \DigiForge\Research\ResearchProvider {
   public function status():array{return ['available'=>true];}
   public function generate(array $research,string $attemptKey):array|WP_Error {return ['raw'=>'{"scores":{},"concepts":[],"approval":"APPROVE"}','provenance'=>['method'=>'governed_ai','provider'=>'stub','model'=>'stub','response_id'=>'stub','generated_at'=>'2026-11-03T12:00:00Z']];}
  };
  $repo=new Repo($provider);$a=$repo->create($f['research']);self::assertWPError($repo->assist('personalized_pod',$a['hash']));self::assertNull($repo->read('personalized_pod',$a['hash'])['record']['response']);
  self::assertWPError($repo->importResponse('personalized_pod',$a['hash'],json_encode($f['response']),['method'=>'governed_ai']));
 }
 public function testResearchRevisionUpdatesEvidenceAndRetainsLineage():void {
  $f=$this->fixture();$repo=new Repo();$a=$repo->create($f['research']);$changed=$f['research'];$changed['sources'][0]['excerpt']='Changed observation with a new review limitation.';
  $r=$repo->revise('personalized_pod',$a['hash'],'Update source capture',$changed);self::assertSame($changed['sources'][0]['excerpt'],$r['record']['research']['sources'][1]['excerpt']);self::assertSame($a['hash'],$r['record']['parent_hash']);
 }
 public function testRevisionPanelPreservesMultipleCompetitorSources():void {
  $f=$this->fixture();$more=$f['research']['sources'][0];$more['id']='e2';$more['url']='https://www.etsy.com/search?q=anniversary+poster';$f['research']['sources'][]=$more;
  $v=(new Repo())->create($f['research']);$_GET['df_research_version']=$v['hash'];ob_start();(new \DigiForge\Portal\CommercialResearchPanel())->render('personalized_pod');$html=ob_get_clean();unset($_GET['df_research_version']);
  self::assertStringContainsString('value="e1"',$html);self::assertStringContainsString('value="e2"',$html);
 }
 public function testPortalResearchToApprovedAssetKeepsLaneBEnvelope():void {
  $panel=new \DigiForge\Portal\CommercialResearchPanel();$f=$this->fixture();$a=$panel->process(['operation'=>'research']+$f['research']);
  $b=$panel->process(['operation'=>'concepts','shop_key'=>'personalized_pod','workflow_hash'=>$a['hash'],'method'=>'ai_import','provider'=>'external-assistant-claimed','model'=>'claimed-model','response_id'=>'capture-001','generated_at'=>'2026-11-03T12:00:00Z','response_json'=>json_encode($f['response'])]);self::assertIsArray($b);
  $c=$panel->process(['operation'=>'select','shop_key'=>'personalized_pod','workflow_hash'=>$b['hash'],'concept'=>'1']);
  $a3=$panel->process(['operation'=>'design_approve_concept','shop_key'=>'personalized_pod','workflow_hash'=>$c['hash'],'sources_reviewed'=>'1','buyer_intent_reviewed'=>'1','gifting_occasion_reviewed'=>'1','note'=>'Human reviewed source and selected concept']);
  $p3=$panel->process(['operation'=>'design_prepare','shop_key'=>'personalized_pod','workflow_hash'=>$c['hash'],'factory_hash'=>$a3['design_factory_hash'],'profile'=>'fl03_mug_11oz','layout_notes'=>'Original outer frame','typography'=>'Licensed native serif','font_license_reference'=>'Human font-license record','palette'=>'#123456,#ffffff','asset_requirements'=>'Original fixed paths and separate native names','native_regions_json'=>'[{"name":"names","kind":"text","x":500,"y":400,"width":1400,"height":300}]']);self::assertIsArray($p3);
  $d=$panel->process(['operation'=>'design_import','shop_key'=>'personalized_pod','workflow_hash'=>$c['hash'],'factory_hash'=>$p3['design_factory_hash'],'svg'=>file_get_contents(__DIR__.'/../fixtures/design-factory/mug-original.svg'),'rasters_json'=>'[]','rights_note'=>'Original geometry; licensed native font','source_note'=>'Manual artwork import']);self::assertIsArray($d);
  $review=['operation'=>'design_review','shop_key'=>'personalized_pod','workflow_hash'=>$c['hash'],'factory_hash'=>$d['design_factory_hash'],'confirm'=>'1','decision'=>'APPROVE','note'=>'Approved exact original artwork'];foreach(\DigiForge\Research\DesignFactoryContract::REVIEW_CRITERIA as $criterion)$review[$criterion]='1';
  $u=wp_get_current_user();$u->remove_cap('manage_digiforge_pod');self::assertWPError($panel->process($review));$u->add_cap('manage_digiforge_pod');
  $e=$panel->process($review);self::assertIsArray($e);$envelope=$e['design_factory']['artwork'];
  self::assertSame('HUMAN_APPROVED',$envelope['approval']['state']);self::assertSame($envelope['version_hash'],$envelope['approval']['evidence_hash']);self::assertSame($envelope['version_hash'],hash('sha256',wp_json_encode($envelope['record'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));self::assertFalse($envelope['supplier_geometry_verified']);self::assertTrue($envelope['release_hold']);
 }
 public function testRationalePersistenceFailureNeverCommitsArtworkApproval():void {
  $f=$this->fixture();$repo=new Repo();$a=$repo->create($f['research']);$b=$repo->importResponse('personalized_pod',$a['hash'],json_encode($f['response']),['method'=>'manual','provider'=>'human','model'=>'none','response_id'=>'authored','generated_at'=>'2026-11-03T12:00:00Z']);$c=$repo->select('personalized_pod',$b['hash'],1);
  $art=['svg'=>'<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="#fff"/></svg>','width_px'=>100,'height_px'=>100,'dpi'=>300,'native_layers'=>[],'rights_note'=>'Original geometry','safe_area_reviewed'=>true,'font_license_reviewed'=>true,'mockup_reviewed'=>true];
  $d=$repo->attach('personalized_pod',$c['hash'],$art,['originality_reviewed'=>true,'licensing_reviewed'=>true,'note'=>'Reviewed original paths']);self::assertIsArray($d);
  $fault=static function($query){return str_contains($query,'INSERT INTO')&&str_contains($query,'digiforge_commercial_version_')?'SELECT 1 WHERE 0=1':$query;};add_filter('query',$fault);
  try {self::assertWPError($repo->decide('personalized_pod',$d['hash'],'APPROVE','This rationale must survive'));}finally{remove_filter('query',$fault);}
  self::assertNull((new \DigiForge\Research\OpportunityDesignRepository())->read('personalized_pod',$d['record']['artwork_version_hash'])['approval']);
  self::assertWPError($repo->decide('personalized_pod',$d['hash'],'APPROVE',"Invalid UTF-8 \xFF"));self::assertNull($repo->read('personalized_pod',$d['hash'])['artwork']['approval']);
  $decisionFault=static function($query){return str_contains($query,'INSERT INTO')&&str_contains($query,'digiforge_opportunity_personalized_pod_')&&str_contains($query,'_decision')?'SELECT 1 WHERE 0=1':$query;};add_filter('query',$decisionFault);
  try {$uncertain=$repo->decide('personalized_pod',$d['hash'],'APPROVE','Durable rationale before uncertain decision');self::assertWPError($uncertain);}finally{remove_filter('query',$decisionFault);}
  $intent=$repo->read('personalized_pod',$uncertain->get_error_data()['review_intent_hash']);self::assertIsArray($intent);self::assertSame('Durable rationale before uncertain decision',$intent['record']['revision_note']);self::assertSame('REVIEW_SUBMITTED',$intent['record']['stage']);self::assertNull($intent['artwork']['approval']);
 }
}
