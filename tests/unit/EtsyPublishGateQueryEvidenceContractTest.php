<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyPublishGateQueryEvidenceContractTest extends TestCase {
 public function testAllThreeAuthorityReadsRejectFalseQueryOutcomes():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyPublishAuthorizationGate.php');
  self::assertSame(3,substr_count($s,'$queryResult===false'));
  self::assertSame(3,substr_count($s,'$wpdb->flush();'));
  self::assertSame(3,substr_count($s,'isset($wpdb->last_result[0])'));
  self::assertStringNotContainsString('$wpdb->get_row(',$s);
  self::assertStringContainsString("'network_request_permitted'=>false",$s);
 }
}
