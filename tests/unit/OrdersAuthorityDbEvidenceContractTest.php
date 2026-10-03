<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class OrdersAuthorityDbEvidenceContractTest extends TestCase{
 public function testReadsAndPersonalizationTransitionSeparateDbFailure():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Orders/Repository.php');
  foreach(['array|WP_Error|null','order_evidence_unavailable','transition_failed',"\$wpdb->last_error=''"] as $n)self::assertStringContainsString($n,$s);
  self::assertGreaterThanOrEqual(7,substr_count($s,'is_wp_error('));
  self::assertStringContainsString("\$ok===false||!empty(\$wpdb->last_error)",$s);
 }
}