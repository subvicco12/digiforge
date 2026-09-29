<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyGovernedListingImageExecutionContractTest extends TestCase {
 public function testControllerExposesBoundedApprovedImageUpload():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/EtsyControlledExecutionController.php');
  foreach(["/etsy/controlled-listing-image","asset_revision_id","release_bundle_id","checksum_sha256","EtsyMultipartImageRequest::build","EtsyDraftListingOperations::uploadImage","ETSY_DRAFT_IMAGE","ATTACH_IMAGE","ETSY_CONTROLLED_IMAGE_ALREADY_ATTEMPTED","publish_permitted'=>false","Idempotency-Key"] as $n)self::assertStringContainsString($n,$s);
 }
 public function testImagePathUsesExistingControlledMultipartPipeline():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/EtsyControlledExecutionController.php');
  foreach(["EtsyOperationPreparationService","EtsyTokenMetadataBridge","EtsyControlledDraftExecutionCoordinator","confirmedCreateForScope"] as $n)self::assertStringContainsString($n,$s);
 }
}
