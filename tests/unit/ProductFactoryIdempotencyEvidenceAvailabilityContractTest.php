<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductFactoryIdempotencyEvidenceAvailabilityContractTest extends TestCase{
 public function testCreateAndConflictRecoveryFailClosedOnUnavailableReplayEvidence():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/Repository.php');
  self::assertStringContainsString('private function find_by_key(string $type, string $key): array|\\WP_Error|null',$s);
  self::assertStringContainsString("\$wpdb->last_error = ''",$s);
  self::assertStringContainsString('Product Factory idempotency evidence could not be read.',$s);
  self::assertGreaterThanOrEqual(2,substr_count($s,'if (is_wp_error($existing)) { return $existing; }'));
  self::assertGreaterThanOrEqual(2,substr_count($s,"['idempotent_replay' => true]"));
 }
}
