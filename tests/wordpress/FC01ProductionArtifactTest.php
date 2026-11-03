<?php
declare(strict_types=1);
require_once __DIR__.'/Support/ReviewedLocalFixture.php';
use DigiForge\POD\FC01ProductionArtifact as Artifact;
use DigiForge\Portal\ReviewedAcceptanceContract as Contract;
final class FC01ProductionArtifactTest extends WP_UnitTestCase {
 private array $actors;
 private array $template;
 private array $review;
 private int $outbound=0;
 public function denyHttp(mixed $pre):WP_Error {++$this->outbound;return new WP_Error('synthetic_network_denied');}
 protected function tearDown():void {remove_filter('pre_http_request',[$this,'denyHttp']);self::assertSame(0,$this->outbound,'Offline artifact preparation must never request HTTP.');parent::tearDown();}
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();$this->actors=ReviewedLocalFixture::actors('personalized_pod');add_filter('pre_http_request',[$this,'denyHttp']);}
 private function fixture(bool $review=true):array {
  global $wpdb;$t=DigiForge\Database\Tables::class;$id='fc01-artifact-'.wp_generate_uuid4();
  $area=['position'=>'front','decoration_method'=>'dtg','width_px'=>1800,'height_px'=>1400,'dpi'=>300,'qa_geometry'=>['safe_zone'=>['x'=>0,'y'=>0,'width'=>1800,'height'=>1400],'seam_exclusions'=>[],'min_image_dpi'=>300,'bleed'=>['trim_box'=>['x'=>0,'y'=>0,'width'=>1800,'height'=>1400],'margins_px'=>['left'=>0,'right'=>0,'top'=>0,'bottom'=>0]]]];
  $this->template=digiforge_test_printify_template(['shop_key'=>'personalized_pod','template_id'=>$id,'template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>101,'provider_id'=>2,'variant_ids'=>[202],'print_areas'=>[$area],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'TEXT_ONLY','template_status'=>'GEOMETRY_LOCKED','environment'=>'sandbox']);self::assertNotWPError($this->template);
  $row=$this->template;unset($row['shop_key']);$row['variant_ids']=wp_json_encode($row['variant_ids']);$row['print_areas']=wp_json_encode($row['print_areas']);$row['created_by']=get_current_user_id();$row['created_at']=current_time('mysql',true);$row['updated_at']=$row['created_at'];self::assertSame(1,$wpdb->insert($t::pod_production_templates(),$row),$wpdb->last_error);
  self::assertSame(1,$wpdb->insert($t::shop_ai_usage(),['shop_key'=>'personalized_pod','workflow'=>'production_template','stage'=>'template_prepare','model_key'=>$this->template['fingerprint'],'run_id'=>'synthetic','quantity'=>1,'idempotency_key'=>$id,'occurred_at'=>current_time('mysql',true)]));
  $area=$this->template['print_areas'][0];$ref=['shop_key'=>'personalized_pod','reference'=>'local://synthetic/geometry-not-certified','sha256'=>$area['_catalog_bindings'][0]['response_sha256']];
  $d=['policy'=>DigiForge\Portal\ApprovedAcceptancePolicy::rules('BP-seam-panel-render'),'environment'=>'sandbox','subject'=>['scope'=>'PRODUCT_VERSION','product_id'=>'synthetic-fc01','product_version'=>'2','supplier_id'=>'synthetic-printify'],'period_start'=>'2020-01-01T00:00:00Z','period_end'=>'2090-01-01T00:00:00Z','source_records'=>[$ref],'observations'=>['template_sha256'=>$this->template['fingerprint'],'geometry_sha256'=>hash('sha256',DigiForge\Listings\Validator::canonicalJson(['front'=>$area])),'panels'=>[['id'=>'front','artifact_sha256'=>str_repeat('a',64),'preview_sha256'=>str_repeat('a',64),'geometry'=>$area]],'alignment_review_sha256'=>str_repeat('b',64)]];
  if($review){$p=Contract::propose('personalized_pod',['version'=>2,'criterion'=>'BP-seam-panel-render','scope'=>$id,'workflow'=>'design','definition'=>$d],time()-1,time()+3600,wp_generate_uuid4(),0);self::assertNotWPError($p);wp_set_current_user($this->actors['reviewer']);$this->review=Contract::decide('personalized_pod','BP-seam-panel-render',$id,$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4());self::assertNotWPError($this->review);wp_set_current_user($this->actors['author']);}
  return ['shop_key'=>'personalized_pod','template_key'=>$id,'template_version'=>1,'template_sha256'=>$this->template['fingerprint'],'variant_id'=>202,'position'=>'front','decoration_method'=>'dtg','geometry_review_scope'=>$id,'personalization'=>['family_name'=>'Avery & Noël','established'=>'2016']];
 }
 public function testCurrentReviewedGeometryProducesNonAuthorizingPinnedRequest():void {
  $i=$this->fixture();$a=new Artifact();$r=$a->prepare($i);self::assertNotWPError($r);self::assertSame($r,$a->prepare($i));self::assertSame(1800,$r['area']['width_px']);self::assertSame(300,$r['area']['dpi']);self::assertSame('UNVERIFIED',$r['acceptance_state']);self::assertFalse($r['production_authorized']);self::assertFalse($r['supplier_certified']);self::assertSame($i['personalization'],$r['personalization']);self::assertTrue(DigiForge\Core\Settings::get('stop_all'));self::assertTrue(DigiForge\Core\Settings::safety_locked());
  if(getenv('DF_FC01_PRODUCTION_FIXTURE'))file_put_contents(getenv('DF_FC01_PRODUCTION_FIXTURE'),wp_json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
 }
 public function testMissingReviewAndUnsupportedIdentityDeny():void {
  $i=$this->fixture(false);self::assertWPError((new Artifact())->prepare($i));
  foreach([['shop_key'=>'digital'],['variant_id'=>203],['position'=>'back'],['decoration_method'=>'embroidery'],['template_sha256'=>str_repeat('0',64)],['extra'=>true]]as $change)self::assertWPError((new Artifact())->prepare(array_replace($i,$change)));
 }
 public function testRevokedReviewAndCatalogDriftImmediatelyDeny():void {
  $i=$this->fixture();$a=new Artifact();self::assertNotWPError($a->prepare($i));wp_set_current_user($this->actors['reviewer']);self::assertNotWPError(Contract::decide('personalized_pod','BP-seam-panel-render',$i['geometry_review_scope'],$this->review['revision'],$this->review['proposal_hash'],'REVOKED',wp_generate_uuid4()));wp_set_current_user($this->actors['author']);self::assertWPError($a->prepare($i));
 }
 public function testCurrentCatalogChangedSourceRevisionOrDimensionsCannotBeReused():void {
  $i=$this->fixture();$a=new Artifact();self::assertNotWPError($a->prepare($i));global $wpdb;$t=DigiForge\Database\Tables::pod_catalog();$wpdb->update($t,['source_revision'=>'synthetic-drift'],['provider'=>'printify','environment'=>'sandbox','provider_product_key'=>'101','provider_variant_key'=>'2:202']);self::assertWPError($a->prepare($i));
 }
 public function testBuyerFieldsAndOptionalOmissionPreserveExactBytes():void {
  $i=$this->fixture();$a=new Artifact();$i['personalization']=['family_name'=>'  Éva  '];$r=$a->prepare($i);self::assertNotWPError($r);self::assertSame('  Éva  ',$r['text_runs']['family_name']);self::assertArrayNotHasKey('established',$r['text_runs']);
  foreach(['😀',"e\u{0301}",str_repeat('W',48),str_repeat('i',49)]as $name){$i['personalization']['family_name']=$name;self::assertWPError($a->prepare($i));}
 }
 public function testMissingBleedDpiOrSafeZoneCannotBecomeExportGeometry():void {
  $i=$this->fixture();global $wpdb;$table=DigiForge\Database\Tables::pod_production_templates();$prior=$this->template['fingerprint'];foreach(['dpi','safe_zone','bleed']as $field){$row=$this->template;$area=&$row['print_areas'][0];if($field==='dpi')unset($area['dpi']);else unset($area['qa_geometry'][$field]);unset($area);$normalized=DigiForge\POD\ProductionTemplateContract::normalize($row);$wpdb->update($table,['print_areas'=>wp_json_encode($normalized['print_areas']),'fingerprint'=>$normalized['fingerprint']],['template_id'=>$i['template_key']]);$wpdb->update(DigiForge\Database\Tables::shop_ai_usage(),['model_key'=>$normalized['fingerprint']],['workflow'=>'production_template','model_key'=>$prior]);$prior=$normalized['fingerprint'];$bad=$i;$bad['template_sha256']=$normalized['fingerprint'];$blocked=(new Artifact())->prepare($bad);self::assertWPError($blocked);self::assertSame('fc01_production_artifact_blocked',$blocked->get_error_code());}
 }
 public function testAnonymousAndLostShopAssignmentCannotReadRequest():void {$i=$this->fixture();wp_set_current_user(0);self::assertWPError((new Artifact())->prepare($i));wp_set_current_user($this->actors['author']);ShopAccessFixture::reset();self::assertWPError((new Artifact())->prepare($i));}
 public function testRealOfflineExportParityAndRevocationDuringCompilation():void {
  $i=$this->fixture();$a=new Artifact();$python=getenv('DIGIFORGE_CUTOUT_PYTHON');
  if(!is_string($python)||$python===''){self::assertWPError($a->export($i));return;}
  $r=$a->export($i);self::assertNotWPError($r);self::assertSame($r['png'],$r['preview_png']);self::assertSame($r['png'],$r['final_png']);self::assertSame([1800,1400],array_slice(getimagesizefromstring($r['png']),0,2));self::assertSame(hash('sha256',$r['png']),$r['receipt']['png_sha256']);self::assertSame($r,$a->export($i));self::assertFalse($r['production_authorized']);
  $blank=$i;$blank['personalization']['established']="\u{2028}\u{2029}";self::assertNotWPError($a->export($blank));
  global $wpdb;$table=DigiForge\Database\Tables::pod_production_templates();$reads=0;
  $race=function(string $sql)use(&$reads,$table,$i):string {if(str_starts_with($sql,'SELECT * FROM '.$table.' WHERE template_id=')){++$reads;if($reads===2){wp_set_current_user($this->actors['reviewer']);$revoked=Contract::decide('personalized_pod','BP-seam-panel-render',$i['geometry_review_scope'],$this->review['revision'],$this->review['proposal_hash'],'REVOKED',wp_generate_uuid4());self::assertNotWPError($revoked);wp_set_current_user($this->actors['author']);}}return $sql;};
  add_filter('query',$race);try{self::assertWPError($a->export($i));self::assertSame(2,$reads);}finally{remove_filter('query',$race);}
 }

}
