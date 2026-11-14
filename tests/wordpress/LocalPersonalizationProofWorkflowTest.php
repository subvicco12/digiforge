<?php
declare(strict_types=1);
use DigiForge\Portal\Shop2ProductWorkflowPanel;
use DigiForge\POD\{ProductIntelligence,ProductIntelligenceRepository};
use DigiForge\Research\DesignFactoryContract;
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
final class LocalPersonalizationProofWorkflowTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();ShopAccessFixture::reset();$id=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($id);ShopAccessFixture::authorizeActor($id,['personalized_pod'],DigiForge\Core\Capabilities::ALL);}
 private function call(array $p):array {$r=(new Shop2ProductWorkflowPanel())->process($p+['shop_key'=>'personalized_pod']);self::assertIsArray($r,is_wp_error($r)?$r->get_error_code():'');return $r;}
 private function snapshot():string{
  $sections=['concepts'=>[['V2 ID'=>'DG2-001','Physical Product'=>'PP-001'],['V2 ID'=>'DG2-002','Physical Product'=>'PP-002']],'variants'=>[],'print_areas'=>[]];
  foreach([[5,7,9,2475,1155],[6,8,10,2418,3018]] as [$b,$p,$v,$w,$h]){$id=['Blueprint ID'=>(string)$b,'Provider ID'=>(string)$p,'Variant ID'=>(string)$v];$sections['variants'][]=$id+['Title'=>'Synthetic variant'];$sections['print_areas'][]=$id+['Print Area'=>'front','Decoration'=>'sublimation','Width PX'=>(string)$w,'Height PX'=>(string)$h];}
  $p=['schema_version'=>1,'shop_key'=>'personalized_pod','business_key'=>'digicraftifygoods','catalog_key'=>'digicraftifygoods-master-500-v2','sources'=>[['id'=>'synthetic','name'=>'synthetic retained source','sha256'=>str_repeat('a',64),'modified_at'=>'2026-11-05T00:00:00Z']],'sections'=>[]];foreach($sections as $s=>$rows)$p['sections'][$s]=['source_id'=>'synthetic','sheet'=>$s,'rows'=>$rows,'rows_sha256'=>ProductIntelligence::rowsHash($rows)];$r=(new ProductIntelligenceRepository())->import($p);self::assertNotWPError($r);return $r['snapshot_id'];
 }
 /** @dataProvider profiles */
 public function testScopedApprovedProofWithoutStorageOrHttp(string $family,int $b,int $p,int $v,string $concept):void {
  $calls=0;$deny=static function()use(&$calls){++$calls;return new WP_Error('no_network','No network in proof acceptance');};add_filter('pre_http_request',$deny);
  try {
   $start=$this->call(['operation'=>'supplier_start','snapshot_id'=>$this->snapshot(),'concept_id'=>$concept,'product_family'=>$family,'blueprint_id'=>(string)$b,'provider_id'=>(string)$p,'variant_id'=>(string)$v]);$link=['supplier_workflow_hash'=>$start['supplier_workflow_hash']];
   $research=(new DigiForge\Research\CommercialResearchRepository())->read('personalized_pod',$start['hash'])['record']['research'];$research['sources'][]=['id'=>'buyer_notes','kind'=>'buyer_intent','url'=>'https://example.invalid/isolated-review','observed_at'=>'2026-11-05T00:00:00Z','excerpt'=>'Synthetic buyer-intent observation, not actual demand','provenance'=>'Isolated test only'];
   $revised=$this->call($link+['operation'=>'revise_research','workflow_hash'=>$start['hash'],'title'=>$research['title'],'product_family'=>$family,'sources'=>$research['sources'],'note'=>'Add manually observed buyer intent']);$start['hash']=$revised['hash'];$bad=$research['sources'];$bad[0]['url']='https://foreign.invalid/';self::assertWPError((new Shop2ProductWorkflowPanel())->process($link+['operation'=>'revise_research','workflow_hash'=>$start['hash'],'title'=>$research['title'],'product_family'=>$family,'sources'=>$bad,'note'=>'Cannot replace supplier source','shop_key'=>'personalized_pod']));
   $f=json_decode(file_get_contents(__DIR__.'/../fixtures/research/commercial-research.json'),true);$response=$f['response'];foreach($response['scores'] as &$score)$score=null;unset($score);foreach($response['concepts'] as &$c)$c['source_ids']=['supplier_catalog'];unset($c);
   $r=$this->call($link+['operation'=>'concepts','workflow_hash'=>$start['hash'],'method'=>'manual','response_json'=>wp_json_encode($response)]);
   $selected=$this->call($link+['operation'=>'select','workflow_hash'=>$r['hash'],'concept'=>'1']);$ctx=$link+['workflow_hash'=>$selected['hash']];
   $a=$this->call($ctx+['operation'=>'design_approve_concept','sources_reviewed'=>'1','buyer_intent_reviewed'=>'1','gifting_occasion_reviewed'=>'1','note'=>'Synthetic isolated review; no measured market demand']);
   $spec=$this->call($ctx+['operation'=>'design_prepare','factory_hash'=>$a['design_factory_hash'],'profile'=>$family==='mug'?'fl03_mug_11oz':'fl04_poster_8x10','layout_notes'=>'Original art outside native reservation','typography'=>'Licensed type','font_license_reference'=>'Synthetic license evidence','palette'=>'#123456,#ffffff','asset_requirements'=>'Original SVG','native_regions_json'=>wp_json_encode([['name'=>'names','kind'=>'text','x'=>500,'y'=>400,'width'=>1400,'height'=>300]])]);
   $asset=$this->call($ctx+['operation'=>'design_import','factory_hash'=>$spec['design_factory_hash'],'svg'=>file_get_contents(__DIR__.'/../fixtures/design-factory/'.$family.'-original.svg'),'rasters_json'=>'[]','rights_note'=>'Synthetic original artwork','source_note'=>'Isolated manual import']);
   $approved=$this->call($ctx+['operation'=>'design_review','factory_hash'=>$asset['design_factory_hash'],'decision'=>'APPROVE','note'=>'Synthetic isolated human review','confirm'=>'1']+array_fill_keys(DesignFactoryContract::REVIEW_CRITERIA,'1'));
   $proofPost=$ctx+['operation'=>'proof_preview','factory_hash'=>$approved['design_factory_hash'],'samples_json'=>wp_json_encode(['names'=>'A & <B>'])];
   $proof=$this->call($proofPost);self::assertStringContainsString('A &amp; &lt;B&gt;',$proof['proof']['svg']);self::assertFalse($proof['proof']['receipt']['external_execution_authorized']);
   self::assertWPError((new Shop2ProductWorkflowPanel())->process(array_replace($proofPost,['shop_key'=>'digital'])));
   self::assertWPError((new Shop2ProductWorkflowPanel())->process(array_replace($proofPost,['shop_key'=>'personalized_pod','factory_hash'=>$spec['design_factory_hash']])));
   self::assertWPError((new Shop2ProductWorkflowPanel())->process(array_replace($proofPost,['shop_key'=>'personalized_pod','samples_json'=>wp_json_encode(['names'=>str_repeat('W',100)])])));
   $proofAgain=$this->call($proofPost);self::assertSame($proof,$proofAgain);
   [$w,$h]=$family==='mug'?[2475,1155]:[2418,3018];$fixture=$family==='mug'?'fl-03':'fl-04';
   for($i=1;$i<=5;$i++)$this->call($ctx+['operation'=>'mockup_import','factory_hash'=>$approved['design_factory_hash'],'png_base64'=>base64_encode($this->png($i)),'note'=>'Synthetic isolated mockup '.$i]);
   $e=json_decode(file_get_contents(__DIR__.'/../fixtures/product-packages/'.$fixture.'.json'),true);
   $e['template']=['template_id'=>'supplier-'.$family,'template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>$b,'provider_id'=>$p,'variant_ids'=>[$v],'personalization_pipeline'=>'PRINTIFY_NATIVE','personalization_engine'=>'MANUAL_DECLARATION','template_status'=>'DRAFT','print_areas'=>[['position'=>'front','decoration_method'=>'sublimation','width_px'=>$w,'height_px'=>$h]]];
   $e['specification']['native_fields']=[['layer'=>'names','type'=>'TEXT','required'=>true,'max_characters'=>30,'provider_field_id'=>null]];
   $pc=$ctx+['factory_hash'=>$approved['design_factory_hash']];$pkg=$this->call($pc+['operation'=>'package_prepare','evidence_mode'=>'import','evidence_json'=>wp_json_encode($e)]);
   $post=$pc+['operation'=>'package_export','package_hash'=>$pkg['package_hash'],'confirm'=>'1','shop_key'=>'personalized_pod'];self::assertWPError((new Shop2ProductWorkflowPanel())->process($post));
   $this->call($pc+['operation'=>'package_decide','package_hash'=>$pkg['package_hash'],'decision'=>'APPROVE','note'=>'Held package review only, production remains blocked','confirm'=>'1']);
   self::assertWPError((new Shop2ProductWorkflowPanel())->process($post),'Legacy worksheet has no current selected proof/business binding.');$legacyWorkflow=new DigiForge\POD\Shop2ProductWorkflow();$raw=$legacyWorkflow->export($legacyWorkflow->read($post['supplier_workflow_hash']),$pkg['package_hash']);self::assertIsString($raw);$out=['package_export'=>$raw];$export=json_decode($out['package_export'],true);self::assertSame($b,$export['supplier_selection']['blueprint_id']);self::assertSame($concept,$export['supplier_selection']['concept_id']);self::assertSame('UNSUBMITTED_LOCAL_WORKSHEET',$export['etsy_draft']['state']);self::assertSame($e['listing']['title'],$export['etsy_draft']['fields']['title']);self::assertFalse($export['external_execution_authorized']);self::assertFalse($export['etsy_draft']['publishing_authorized']);self::assertNotContains('FIVE_DISTINCT_REVIEWED_MOCKUPS_REQUIRED',$export['remaining_gates']);self::assertCount(5,$export['etsy_draft']['media']);self::assertContains('SUPPLIER_MOCKUP_AUTHENTICITY_UNVERIFIED',$export['remaining_gates']);
   self::assertWPError((new Shop2ProductWorkflowPanel())->process($pc+['shop_key'=>'personalized_pod','operation'=>'package_bundle','package_hash'=>$pkg['package_hash'],'confirm'=>'1']));$legacyWorkflow=new DigiForge\POD\Shop2ProductWorkflow();$bytes=DigiForge\POD\Shop2ReviewBundle::build($legacyWorkflow->read($pc['supplier_workflow_hash']),$pkg['package_hash']);self::assertIsString($bytes);$bundle=['package_bundle'=>$bytes];$tmp=tempnam(sys_get_temp_dir(),'df-proof-');file_put_contents($tmp,$bundle['package_bundle']);$zip=new ZipArchive();self::assertTrue($zip->open($tmp,ZipArchive::CHECKCONS));
   $receipt=json_decode($zip->getFromName('proof/receipt.json'),true);self::assertFalse($receipt['production_file']);self::assertSame(hash('sha256',$zip->getFromName('proof/local-sample.svg')),$receipt['proof_sha256']);
   $manifest=json_decode($zip->getFromName('manifest.json'),true);foreach($manifest['files'] as $f)self::assertSame($f['sha256'],hash('sha256',$zip->getFromName($f['path'])));
   self::assertSame($out['package_export'],$zip->getFromName('held-package.json'));$zip->close();unlink($tmp);
   $other=$this->call(['operation'=>'supplier_start','snapshot_id'=>$this->snapshot(),'concept_id'=>($concept==='DG2-001'?'DG2-002':'DG2-001'),'product_family'=>$family,'blueprint_id'=>(string)$b,'provider_id'=>(string)$p,'variant_id'=>(string)$v]);
   self::assertWPError((new Shop2ProductWorkflowPanel())->process(array_replace($proofPost,['shop_key'=>'personalized_pod','supplier_workflow_hash'=>$other['supplier_workflow_hash'],'workflow_hash'=>$other['hash']])));
   wp_set_current_user(0);self::assertWPError((new Shop2ProductWorkflowPanel())->process($proofPost+['shop_key'=>'personalized_pod']));
   self::assertSame(0,$calls);
  } finally {remove_filter('pre_http_request',$deny);}
 }
 private function png(int $shade,int $width=8,int $height=8,bool $density=false):string{$chunk=static fn($t,$d)=>pack('N',strlen($d)).$t.$d.hash('crc32b',$t.$d,true);return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$width,$height,8,2,0,0,0)).($density?$chunk('pHYs',pack('NNC',11811,11811,1)):'').$chunk('IDAT',gzcompress(str_repeat("\x00".str_repeat(chr($shade)."\x22\x33",$width),$height))).$chunk('IEND','');}
 public static function profiles():array{return [['mug',5,7,9,'DG2-001'],['poster',6,8,10,'DG2-002']];}
}
