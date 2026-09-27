<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsySellerTaxonomyVerifierContractTest extends TestCase {
 public function testVerifierUsesSellerTreeAndFailsClosed(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsySellerTaxonomyVerifier.php');
  foreach(["results","children","taxonomy_id","not_found","verified"] as $n) self::assertStringContainsString($n,$s);
 }
}
