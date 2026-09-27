<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsySellerTaxonomyDiscoveryContractTest extends TestCase {
 public function testDiscoveryIsBoundedReadOnlyCandidateSearch(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsySellerTaxonomyDiscovery.php');
  foreach(['terms','candidates','taxonomy_id','path','term_matches','min(50'] as $n) self::assertStringContainsString($n,$s);
  foreach(['wp_remote_post','wp_remote_request','publish','createDraftListing'] as $n) self::assertStringNotContainsString($n,$s);
 }
 public function testClientUsesSameAuthenticatedGetOnlyTree(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Integrations/EtsySellerTaxonomyClient.php');
  foreach(['function discover','EtsySellerTaxonomyDiscovery::search','wp_remote_get','seller-taxonomy/nodes','ConnectionTester'] as $n) self::assertStringContainsString($n,$s);
  foreach(['wp_remote_post','wp_remote_request'] as $n) self::assertStringNotContainsString($n,$s);
 }
}