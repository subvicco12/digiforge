<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/../support/MasterCatalogStoredEvidenceFixture.php';
use DigiForge\Database\Tables;

/** Blueprint portal table22/r7 and r8; native evidence only, never supplier/artwork certification. */
final class PortalCatalogAcceptanceTest extends WP_UnitTestCase
{
 private mixed $httpGuard;
 private int $httpCalls=0;
 private array $catalogIds=[];
 protected function setUp():void {
  parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);
  $this->httpGuard=function(){++$this->httpCalls;return new WP_Error('offline_catalog_portal','External HTTP forbidden.');};add_filter('pre_http_request',$this->httpGuard,1,3);
 }
 protected function tearDown():void {
  global $wpdb;remove_filter('pre_http_request',$this->httpGuard,1);self::assertSame(0,$this->httpCalls);
  $wpdb->query('ROLLBACK');foreach($this->catalogIds as $id){$wpdb->delete(Tables::catalog_acceptance_evidence(),['catalog_version_id'=>$id]);$wpdb->delete(Tables::catalog_items(),['catalog_version_id'=>$id]);$wpdb->delete(Tables::catalog_versions(),['id'=>$id]);}
  DigiForge\Core\Settings::protectProduction();$wpdb->query('START TRANSACTION');parent::tearDown();
 }
 private function render(string $view,string $shop):string {$before=$_GET;try{$_GET=['df_view'=>$view,'df_shop'=>$shop];return (new DigiForge\Portal\Portal())->render();}finally{$_GET=$before;}}
 private function version(string $shop,string $marker):array {
  $repo=new DigiForge\ProductFactory\Repository();$opp=$repo->create('opportunity',['title'=>$marker.' opportunity']);self::assertNotWPError($opp);
  // Explicit stored ancestry is synthetic fixture evidence, not a research-quality approval.
  $candidate=(new DigiForge\Research\Repository())->createCandidate(['title'=>$marker.' candidate','shop_key'=>$shop]);self::assertNotWPError($candidate);global $wpdb;self::assertSame(1,$wpdb->update(Tables::research_candidates(),['opportunity_id'=>$opp['id']],['id'=>$candidate['id']]));
  $family=$repo->create('product_family',['name'=>$marker.' family','opportunity_id'=>$opp['id']]);self::assertNotWPError($family);$product=$repo->create('product',['name'=>$marker.' product','product_family_id'=>$family['id']]);self::assertNotWPError($product);
  $key='portal-version-'.wp_generate_uuid4();$input=['product_id'=>$product['id'],'version_label'=>$marker.'-v1','notes'=>$marker.' immutable specification'];$version=$repo->create('product_version',$input,$key);self::assertNotWPError($version);return compact('repo','product','version','input','key','marker');
 }
 public function testReleasedVersionAndSeparateDraftRemainVisibleWithoutMutatingImmutableHistory():void {
  $own=$this->version('personalized_pod','released-own-'.wp_rand());$other=$this->version('jewelry','released-foreign-'.wp_rand());
  foreach(['REVIEW','APPROVED','RELEASED']as $state)self::assertNotWPError($own['repo']->transition('product_version',(int)$own['version']['id'],$state));
  $released=$own['repo']->find('product_version',(int)$own['version']['id']);$changed=$own['input'];$changed['version_label']=$own['marker'].'-v2';$changed['notes']='New material requires a separate version';
  self::assertWPError($own['repo']->create('product_version',$changed,$own['key']));$next=$own['repo']->create('product_version',$changed,'portal-next-'.wp_generate_uuid4());self::assertNotWPError($next);self::assertSame('DRAFT',$next['state']);
  $html=$this->render('products','personalized_pod');foreach([$released['version_label'],$released['notes'],$next['version_label'],'RELEASED','DRAFT']as $value)self::assertStringContainsString(esc_html($value),$html);self::assertStringNotContainsString($other['marker'],$html);self::assertSame($released,$own['repo']->find('product_version',(int)$released['id']));
  $table=Tables::product_versions();$fault=static fn(string $sql):string=>str_contains($sql,'SELECT * FROM '.$table)?'SELECT * FROM no_such_portal_versions':$sql;add_filter('query',$fault);try{$html=$this->render('products','personalized_pod');self::assertStringContainsString('Evidence unavailable. Source records could not be read',$html);self::assertStringNotContainsString($released['version_label'],$html);}finally{remove_filter('query',$fault);}
 }
 public function testMappingAndPrintAreaVisibilityUsesStoredProductAncestryAndNeverSupplierCertification():void {
  global $wpdb;$now=current_time('mysql',true);self::assertSame(1,$wpdb->insert(Tables::integrations(),['provider'=>'printify','connection_key'=>'portal-fixture-'.wp_generate_uuid4(),'display_name'=>'Offline synthetic connector','environment'=>'sandbox','status'=>'CONFIGURED','enabled'=>0,'config'=>'{}','created_at'=>$now,'updated_at'=>$now]));$rows=[];
  foreach(['personalized_pod','jewelry']as $shop){$flow=$this->version($shop,'mapping-'.$shop.'-'.wp_rand());$production=new DigiForge\Production\Repository();$plan=$production->createPlan(['product_version_id'=>$flow['version']['id'],'plan_key'=>'portal-plan-'.wp_generate_uuid4(),'version_label'=>'fixture-v1','channel'=>'pod','production_type'=>'deterministic']);self::assertNotWPError($plan);$spec=$production->createSpec(['product_version_id'=>$flow['version']['id'],'asset_key'=>'portal-spec-'.wp_generate_uuid4(),'asset_type'=>'print','purpose'=>'Offline print-area evidence only','format'=>'png','width_px'=>800,'height_px'=>1000,'dpi'=>300]);self::assertNotWPError($spec);
   $pod=new DigiForge\POD\Repository();$mapping=$pod->createMapping(['product_version_id'=>$flow['version']['id'],'production_plan_id'=>$plan['id'],'provider'=>'printify','environment'=>'sandbox','provider_product_key'=>$flow['marker'],'provider_variant_key'=>'synthetic-variant','mapping_version'=>$flow['marker'].'-mapping-v1']);self::assertNotWPError($mapping);$area=$pod->createPrintArea(['provider_mapping_id'=>$mapping['id'],'asset_spec_id'=>$spec['id'],'area_key'=>'front','placement'=>$flow['marker'].' placement','width_value'=>800,'height_value'=>1000,'unit'=>'px','dpi_target'=>300,'accepted_formats'=>['png'],'bleed_metadata'=>['fixture_bleed'=>12],'safe_area_metadata'=>['fixture_safe'=>24]]);self::assertNotWPError($area);self::assertSame('DRAFT',$mapping['state']);self::assertSame('DRAFT',$area['state']);$rows[$shop]=compact('flow','mapping','area');
  }
  $html=$this->render('pod_personalized','personalized_pod');self::assertStringContainsString($rows['personalized_pod']['mapping']['mapping_version'],$html);self::assertStringContainsString($rows['personalized_pod']['area']['placement'],$html);self::assertStringNotContainsString($rows['jewelry']['flow']['marker'],$html);preg_match_all('/<tr>.*?<\/tr>/s',$html,$matches);$mappingRows=array_values(array_filter($matches[0],static fn(string $row):bool=>str_contains($row,$rows['personalized_pod']['mapping']['mapping_version'])));$areaRows=array_values(array_filter($matches[0],static fn(string $row):bool=>str_contains($row,$rows['personalized_pod']['area']['placement'])));self::assertCount(1,$mappingRows);self::assertCount(1,$areaRows);self::assertStringContainsString('>DRAFT<',$mappingRows[0]);foreach(['>DRAFT<','>300<','fixture_bleed','fixture_safe','png']as $value)self::assertStringContainsString($value,$areaRows[0]);
  self::assertSame('DRAFT',$wpdb->get_var($wpdb->prepare('SELECT state FROM '.Tables::pod_mappings().' WHERE id=%d',$rows['personalized_pod']['mapping']['id'])));
  $table=Tables::pod_print_areas();$fault=static fn(string $sql):string=>str_contains($sql,'SELECT * FROM '.$table)?'SELECT * FROM no_such_portal_print_area':$sql;add_filter('query',$fault);try{$html=$this->render('pod_personalized','personalized_pod');self::assertStringContainsString('Evidence unavailable. Source records could not be read',$html);self::assertStringNotContainsString($rows['personalized_pod']['area']['placement'],$html);}finally{remove_filter('query',$fault);}
 }
 public function testCompleteStoredMigrationAndHumanDecisionRenderWithoutPromotionAuthority():void {
  $parent=MasterCatalogStoredEvidenceFixture::persistParent();self::assertNotWPError($parent);$this->catalogIds[]=(int)$parent['id'];$candidate=MasterCatalogStoredEvidenceFixture::persistV2('portal-v2-'.wp_generate_uuid4());self::assertNotWPError($candidate);$this->catalogIds[]=(int)$candidate['id'];global $wpdb;$wpdb->query('START TRANSACTION');
  $html=$this->render('pod_personalized','personalized_pod');foreach([$candidate['version_label'],$candidate['source_sha256'],$candidate['fingerprint'],'ALREADY_PERSISTED','Accept exact migration evidence']as $value)self::assertStringContainsString(esc_html($value),$html);
  $decision=(new DigiForge\POD\MasterCatalogV2AcceptanceRepository())->record((int)$candidate['id'],'ACCEPT',get_current_user_id());self::assertNotWPError($decision);$html=$this->render('pod_personalized','personalized_pod');self::assertStringContainsString($decision['acknowledgement_hash'],$html);self::assertStringContainsString('conflicting ACCEPT/REJECT controls are suppressed',$html);self::assertStringNotContainsString('Accept exact migration evidence</button>',$html);self::assertFalse($decision['promotion_authorized']);self::assertFalse($decision['external_execution_authorized']);
  $table=Tables::catalog_versions();$fault=static fn(string $sql):string=>str_contains($sql,'FROM '.$table)?'SELECT * FROM no_such_portal_catalog':$sql;add_filter('query',$fault);try{$html=$this->render('pod_personalized','personalized_pod');self::assertStringContainsString('Governed catalog evidence unavailable',$html);self::assertStringNotContainsString($candidate['fingerprint'],$html);self::assertStringNotContainsString('Accept exact migration evidence</button>',$html);}finally{remove_filter('query',$fault);}
 }
}
