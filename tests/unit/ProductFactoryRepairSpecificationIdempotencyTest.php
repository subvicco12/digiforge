<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductFactoryRepairSpecificationIdempotencyTest extends TestCase {
 public function test_repair_run_uses_unique_development_key():void {
  $source=(string)file_get_contents(__DIR__.'/../../includes/ProductFactory/Orchestrator.php');
  self::assertStringContainsString("\$developmentKey=\$isRepair?\$key.'-development':'u3-auto-candidate-'.\$candidateId.'-'.\$shop.'-development';", $source);
  self::assertSame(1, substr_count($source, "\$isRepair=str_starts_with(\$key,'u3-repair-');"));
 }
}
