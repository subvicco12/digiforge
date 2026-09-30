<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyGovernedListingImageExecutionContractTest extends TestCase {
 public function testControllerExposesBoundedApprovedImageUpload():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/EtsyControlledExecutionController.php');
  foreach(["/etsy/controlled-listing-image","asset_revision_id","release_bundle_id","RELEASE_READY","approved_by","approved_at","checksum_sha256","AssetStorage::absolutePath","EtsyMultipartImageRequest::build","EtsyDraftListingOperations::uploadImage","ETSY_DRAFT_IMAGE","ATTACH_IMAGE","ETSY_CONTROLLED_IMAGE_ALREADY_ATTEMPTED","publish_permitted'=>false","Idempotency-Key"] as $n)self::assertStringContainsString($n,$s);
 }
 public function testDerivedImageRequiresImmutableApprovedBundleLineage():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/EtsyControlledExecutionController.php');
  foreach(["listing_image","local_svg_to_png","source_revision_id","source_asset_spec_id","source_checksum_sha256","source_storage_reference","approval_inherited","external_action_performed","digiforge_etsy_image_lineage"] as $n)self::assertStringContainsString($n,$s);
  self::assertStringContainsString("state']??'')!=='APPROVED'",$s);
  self::assertStringContainsString("release_bundles",$s);
 }
 public function testImagePathUsesExistingControlledMultipartPipeline():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/EtsyControlledExecutionController.php');
  foreach(["byKey","automatic_retry_permitted","EtsyOperationPreparationService","EtsyTokenMetadataBridge","EtsyControlledDraftExecutionCoordinator","confirmedCreateForScope"] as $n)self::assertStringContainsString($n,$s);
 }
}
