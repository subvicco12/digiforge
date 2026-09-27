<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyMultipartDigitalFileRequestContractTest extends TestCase {
 public function testDigitalFileRequiresApprovedBundleAndExactChecksum(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyMultipartDigitalFileRequest.php');
  foreach(["RELEASE_READY","approved_by","approved_at","asset_revision_id","checksum_sha256","hash_file","hash_equals","ETSY_MULTIPART_FILE_PREPARED","publish_permitted'=>false"] as $n) self::assertStringContainsString($n,$s);
 }
 public function testDigitalFileDoesNotReuseImageMimeAllowlist(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyMultipartDigitalFileRequest.php');
  foreach(["image/jpeg","image/png","image/webp","MAX_BYTES"] as $n) self::assertStringNotContainsString($n,$s);
 }
}
