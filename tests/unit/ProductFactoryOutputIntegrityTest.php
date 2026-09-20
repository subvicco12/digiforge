<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductFactoryOutputIntegrityTest extends TestCase{
 public function test_pdf_page_breaks_and_structured_evidence_are_normalized():void{
  $producer=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/LocalAssetProducer.php');
  $orchestrator=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/Orchestrator.php');
  self::assertStringContainsString('PAGE_BREAK',$producer);
  self::assertStringContainsString('preg_split',$producer);
  self::assertStringContainsString('normalizeGeneratedEvidence',$orchestrator);
  self::assertStringContainsString("hash('sha256'",$orchestrator);
  self::assertStringContainsString('serializedAssetBytes',$orchestrator);
  self::assertStringContainsString("['sha256,filename']",$orchestrator);
  self::assertStringNotContainsString("preg_replace('/\\.csv$/i','.txt'",$orchestrator);
  self::assertStringContainsString('selected-language or selected-variant customer delivery',$orchestrator);
  self::assertStringContainsString('AI generation alone is not translation approval',$orchestrator);
  self::assertStringContainsString("'file_checksums'",$orchestrator);
  self::assertStringContainsString('checksum_scope',$orchestrator);
  self::assertStringContainsString('provenance_record.json',$orchestrator);
  self::assertStringContainsString("'evidence_assets_in_customer_package'=>false",$orchestrator);
  self::assertStringContainsString('production_svg_content',$orchestrator);
  self::assertStringContainsString('$content=$decoded',$orchestrator);
  self::assertStringNotContainsString('GENERATED_AFTER_FINAL_EXPORT',$orchestrator);
 }
}
