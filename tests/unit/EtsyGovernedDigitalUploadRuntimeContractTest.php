<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyGovernedDigitalUploadRuntimeContractTest extends TestCase {
 public function testLedgerPersistsPostCreateAndAssetIdentities():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Database/EtsyOperationSchema.php');
  foreach(['VERSION = 4','resource_reference varchar(191)','external_asset_reference varchar(191)'] as $n)self::assertStringContainsString($n,$s);
  $r=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationRecord.php');
  foreach(['resource_reference','ATTACH_IMAGE','UPLOAD_FILE'] as $n)self::assertStringContainsString($n,$r);
 }
 public function testAcceptedFileIdentityIsPersistedAfterConfirmedSuccess():void{
  $l=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyAttemptLifecycleService.php');
  foreach(['external_asset_reference','recordExternalAssetReference'] as $n)self::assertStringContainsString($n,$l);
  $r=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationRepository.php');
  foreach(['recordExternalAssetReference','CONFIRMED_SUCCESS','UPLOAD_FILE','ATTACH_IMAGE','external_asset_reference_conflict'] as $n)self::assertStringContainsString($n,$r);
 }
 public function testUploadRouteUsesGovernedCustomerPackageAndConfirmedCreate():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/EtsyControlledExecutionController.php');
  foreach(['/etsy/controlled-digital-file','confirmedCreateForScope','EtsyCustomerDownloadResolver::resolve','EtsyMultipartDigitalFileRequest::build','EtsyDraftListingOperations::uploadFile','ETSY_DRAFT_FILE','resource_reference','publish_permitted'=>false] as $n)self::assertStringContainsString($n,$s);
  $r=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyCustomerDownloadResolver.php');
  foreach(['RELEASE_READY',"asset_key='customer-package'","asset_type='product_package'","format='zip'",'EtsyCustomerDownloadSelector::select','AssetStorage::absolutePath'] as $n)self::assertStringContainsString($n,$r);
 }
}