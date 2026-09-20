<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductFactoryReservedEvidenceAssetsTest extends TestCase {
 public function test_ai_cannot_duplicate_deterministic_evidence_assets():void {
  $source=(string)file_get_contents(__DIR__.'/../../includes/ProductFactory/Orchestrator.php');
  self::assertStringContainsString("['delivery-manifest','license-provenance','provenance-record']", $source);
  self::assertStringContainsString("['delivery-manifest.json','license-and-provenance.txt','provenance.json']", $source);
  self::assertStringContainsString("'asset_key'=>'delivery-manifest'", $source);
  self::assertStringContainsString("'asset_key'=>'license-provenance'", $source);
  self::assertStringContainsString("'asset_key'=>'provenance-record'", $source);
  self::assertStringContainsString("'filename'=>'provenance.json'", $source);
 }
}
