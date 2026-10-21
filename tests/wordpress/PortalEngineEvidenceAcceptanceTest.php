<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
use DigiForge\Portal\ProductionTemplatePanel;
use DigiForge\POD\DesignFamilyLibrary;
use DigiForge\POD\DeterministicPersonalizationRenderer;
use DigiForge\POD\TransformationEngineContract;
/** Actual operator inventory; local plumbing support never certifies commercial artwork. */
final class PortalEngineEvidenceAcceptanceTest extends WP_UnitTestCase
{
 protected function setUp():void {
  parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
  ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],DigiForge\Core\Capabilities::ALL);
 }
 public function testOperatorSeesEveryActualEngineAndVersionedFamilyWithoutCertification():void {
  ShopAccessFixture::authorizeActor(get_current_user_id(),['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Core\Capabilities::ALL);
  $data=(new ProductionTemplatePanel())->capabilities('personalized_pod');
  self::assertSame('AVAILABLE',$data['query_state']);
  self::assertSame(array_merge(DeterministicPersonalizationRenderer::supportedEngines(),TransformationEngineContract::ENGINES),array_keys($data['engines']));
  self::assertCount(16,$data['engines']);self::assertCount(28,$data['families']);
  self::assertFalse($data['external_execution_authorized']);self::assertFalse($data['supplier_certified']);
  foreach($data['engines'] as $engine=>$cap){self::assertSame('NOT_CERTIFIED',$cap['certification']);self::assertSame(in_array($engine,TransformationEngineContract::ENGINES,true)?'REVIEWED_STORED_RESULT_IMPORT':'DETERMINISTIC_REVIEWED_RECIPE',$cap['adapter']);}
  foreach($data['families'] as $family){$actual=DesignFamilyLibrary::provenance($family['id']);self::assertSame($actual['family_sha256'],$family['family_sha256']);self::assertFalse($family['commercial_certified']);self::assertFalse($family['supplier_certified']);}
  ob_start();ProductionTemplatePanel::render('personalized_pod');$html=(string)ob_get_clean();
  foreach(array_keys($data['engines']) as $engine)self::assertStringContainsString($engine,$html);
  foreach($data['families'] as $family){self::assertStringContainsString($family['family_sha256'],$html);self::assertStringContainsString(esc_html($family['name']),$html);}
  self::assertStringContainsString('TRANSFORMATION_REQUIRED families remain blocked',$html);
  self::assertStringContainsString('VINTAGE_PORTRAIT catalog activation remains disabled',$html);
  self::assertStringContainsString('Source design canvases are not supplier dimensions',$html);
  self::assertStringNotContainsString('name="action"',$html);
  $get=$_GET;try{$_GET=['df_view'=>'pod_personalized','df_shop'=>'personalized_pod'];$portal=(new DigiForge\Portal\Portal())->render();$directory=getenv('DF_PORTAL_FIXTURE_DIR');if(is_string($directory)&&$directory!=='')self::assertSame(strlen($portal),file_put_contents($directory.'/portal-pod_personalized--personalized_pod.html',$portal));foreach(array_keys($data['engines']) as $engine)self::assertStringContainsString($engine,$portal);foreach($data['families'] as $family)self::assertStringContainsString($family['family_sha256'],$portal);self::assertStringContainsString('Versioned design family recipes',$portal);}finally{$_GET=$get;}
 }
 public function testUnassignedAndForeignShopAccessNeverReceivesCapabilityInventory():void {
  foreach(['standard_pod','all','personalized-pod'] as $shop){$data=(new ProductionTemplatePanel())->capabilities($shop);self::assertSame('UNAVAILABLE',$data['query_state']);self::assertSame([],$data['engines']);self::assertSame([],$data['families']);}
  wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
  self::assertSame('UNAVAILABLE',(new ProductionTemplatePanel())->capabilities('personalized_pod')['query_state']);
  ob_start();ProductionTemplatePanel::render('personalized_pod');$html=(string)ob_get_clean();
  self::assertStringNotContainsString('FR01',$html);self::assertStringNotContainsString('DETERMINISTIC_REVIEWED_RECIPE',$html);
 }
}
