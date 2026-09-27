<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyCustomerDownloadSelectorContractTest extends TestCase
{
 public function testSelectorRequiresExplicitApprovedCustomerPackageRoleAndManifestBinding(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyCustomerDownloadSelector.php');
  foreach(["RELEASE_READY","customer-package","product_package","'zip'","'product'","APPROVED","application/zip","asset_spec_id","asset_revision_id","checksum_sha256","hash_equals","ETSY_CUSTOMER_DOWNLOAD_SELECTED"] as $n) self::assertStringContainsString($n,$s);
  foreach(["marketing_asset","evidence_asset","etsy_listing"] as $n) self::assertStringNotContainsString($n,$s);
 }
 public function testExecutorSendsFileNameAndRankAsSeparateMultipartFields(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyControlledHttpExecutor.php');
  self::assertStringContainsString('name="name"',$s);
  self::assertStringContainsString('name="rank"',$s);
  self::assertStringContainsString('if($filePlan)',$s);
  self::assertStringContainsString('name="'.'$field'.'"; filename="'.'$safeFilename'.'"',$s);
 }
}
