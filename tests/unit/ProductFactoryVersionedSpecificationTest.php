<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductFactoryVersionedSpecificationTest extends TestCase {
 public function test_repair_development_creates_capability_version_and_forbids_fake_placeholders():void {
  $source=(string)file_get_contents(__DIR__.'/../../includes/Launch/ExecutionEngine.php');
  self::assertStringContainsString("'Capability Spec ' . \$versionToken", $source);
  self::assertStringContainsString('QR placeholders', $source);
  self::assertStringContainsString('Never invent URLs or require empty destination/link fields', $source);
  self::assertStringContainsString('deterministic DigiForge generation metadata, SHA-256 checksums, creation records', $source);
  self::assertStringContainsString('do not require third-party font-license records when no third-party font is embedded', $source);
 }
}
