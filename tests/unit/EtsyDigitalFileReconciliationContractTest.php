<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyDigitalFileReconciliationContractTest extends TestCase
{
 public function testAcceptedUploadRequiresBoundedListingAndFileIdentity(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyAcceptedResponseParser.php');
  foreach(["UPLOAD_FILE","listing_file_id","ambiguous_file_identity","external_asset_reference","ctype_digit"] as $n) self::assertStringContainsString($n,$s);
 }
 public function testUnknownUploadUsesReadOnlyShopScopedFilesLookup(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationLookupPlan.php');
  foreach(["UPLOAD_FILE","shop_reference","/application/shops/","/listings/","/files","'method'=>'GET'","'mutation_permitted'=>false","'automatic_retry_permitted'=>false"] as $n) self::assertStringContainsString($n,$s);
 }
 public function testFileReconciliationRequiresOneExactMetadataMatch(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationSpecificReconciliation.php');
  foreach(["UPLOAD_FILE","listing_file_id","listing_id","filename","rank","size_bytes","file_evidence_ambiguous","file_evidence_mismatch","count($matches)!==1","external_asset_reference"] as $n) self::assertStringContainsString($n,$s);
 }
 public function testPostCreateGuardAlreadyCoversUploadFile(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyPostCreateSequenceGuard.php');
  foreach(["CONFIRMED_SUCCESS","CREATE_DRAFT","UPLOAD_FILE","resource_reference","external_reference"] as $n) self::assertStringContainsString($n,$s);
 }
}
