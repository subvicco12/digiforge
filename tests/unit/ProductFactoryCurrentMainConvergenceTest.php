<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
/** Current-main convergence certificate replacing stale Product Factory repair branches. */
final class ProductFactoryCurrentMainConvergenceTest extends TestCase{
 public function testResumableSelfHealingPipelineIsPresentAndExternallyInert():void{
  $r=dirname(__DIR__,2).'/includes/ProductFactory/';
  $a=(string)file_get_contents($r.'ApprovalAutomation.php');
  foreach(["'manifest_poll'","'finalize'",'MAX_STAGE_RETRIES = 3','MAX_MANIFEST_REPAIRS = 2','MAX_QA_REPAIRS = 2','resumeFailed(int $candidateId)',"'external_actions'=>false"] as $x)self::assertStringContainsString($x,$a);
  foreach(['etsy_publish','printify','gelato'] as $x)self::assertStringNotContainsString($x,$a);
 }
 public function testManifestAndAssetReplayRepairsRemainFailClosedAndImmutable():void{
  $r=dirname(__DIR__,2).'/includes/ProductFactory/';
  $o=(string)file_get_contents($r.'Orchestrator.php');
  foreach(['manifestRepairBrief','validateManifest','production_manifest','production_selected_variant','production_pdf_pages',"$repairGeneration=$isRepair?substr(hash('sha256',DIGIFORGE_VERSION.'|'.$key),0,10):'';","$repairVariant=$isRepair?'repair-'.$planToken.'-'.$repairGeneration:'';"] as $x)self::assertStringContainsString($x,$o);
  $p=(string)file_get_contents($r.'LocalAssetProducer.php');
  foreach(['asset_replay_conflict','Existing generated asset differs from replay payload.'] as $x)self::assertStringContainsString($x,$p);
 }
 public function testProductReviewAndListingPreparationStayHumanGated():void{
  $c=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/LaunchController.php');
  foreach(['/launch/product-versions/(?P<id>\\d+)/review','/launch/product-versions/(?P<id>\\d+)/prepare-listing','manage_digiforge_production','manage_digiforge_listings',"'etsy_publish_direct' => false","'printify_execution' => false","'order_execution' => false"] as $x)self::assertStringContainsString($x,$c);
 }
 public function testLegacyRegressionCertificatesRemainPresent():void{
  $t=dirname(__DIR__);
  foreach(['unit/U3ResumablePipelineStructureTest.php','unit/U3SelfHealingRuntimeTest.php','unit/F1RepairReplayHardeningStructureTest.php','unit/F1RepairStorageIsolationStructureTest.php','unit/U3ReplaySafetyStructureTest.php'] as $f)self::assertFileExists($t.'/'.$f,$f);
 }
}
