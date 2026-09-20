<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductFactoryFinalManifestContractTest extends TestCase {
 public function test_repair_specs_are_capability_labeled_and_manifest_has_one_bounded_repair():void {
  $engine=(string)file_get_contents(__DIR__.'/../../includes/Launch/ExecutionEngine.php');
  $factory=(string)file_get_contents(__DIR__.'/../../includes/ProductFactory/Orchestrator.php');
  self::assertStringContainsString("str_starts_with(\$key, 'u3-repair-')", $engine);
  self::assertStringContainsString('manifestRepairPrompt', $factory);
  self::assertStringContainsString('after one bounded schema-repair attempt', $factory);
 }
}
