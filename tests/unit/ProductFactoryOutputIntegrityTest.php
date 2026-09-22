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
  self::assertStringContainsString('finalChecksumRows',$orchestrator);
  self::assertStringContainsString("'checksum_sha256'",$orchestrator);
  self::assertStringContainsString('finalizeProvenanceDefinitions',$orchestrator);
  self::assertStringContainsString('finalizeChecksumDefinitions',$orchestrator);
  self::assertStringContainsString('sha256sums?',$orchestrator);
  $qa=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/AutomatedQa.php');
  self::assertStringContainsString('pdf_link_qr_map_scan',$qa);
  self::assertStringContainsString('digiforge_local_text_pdf',$qa);
  self::assertStringContainsString("['sha256,filename']",$orchestrator);
  self::assertStringNotContainsString("preg_replace('/\\.csv$/i','.txt'",$orchestrator);
  self::assertStringContainsString('selected-language or selected-variant customer delivery',$orchestrator);
  self::assertStringContainsString("$reservedEvidence=['delivery-manifest.json','license-and-provenance.txt','provenance.json']",$orchestrator);
  self::assertStringContainsString('after reserved machine evidence is excluded',$orchestrator);
  self::assertStringContainsString("$missing=array_values(array_diff($selectedFiles,$actual))",$orchestrator);
  self::assertStringContainsString('AI generation alone is not translation approval',$orchestrator);
  self::assertStringContainsString("'file_checksums'",$orchestrator);
  self::assertStringContainsString('checksum_scope',$orchestrator);
  self::assertStringContainsString('finalizeProvenanceDefinitions',$orchestrator);
  self::assertStringContainsString('The checksum index separately records the final provenance record hash and excludes itself.',$orchestrator);
  self::assertStringContainsString("'evidence_assets_in_customer_package'=>false",$orchestrator);
  self::assertStringContainsString('production_svg_content',$orchestrator);
  self::assertStringContainsString('$content=$decoded',$orchestrator);
  self::assertStringNotContainsString('GENERATED_AFTER_FINAL_EXPORT',$orchestrator);
  self::assertStringContainsString('approvedPackageFilename',$orchestrator);
  self::assertStringContainsString("'generator_version'=>DIGIFORGE_VERSION",$orchestrator);
  self::assertStringContainsString('pdfGeometry',$producer);
  self::assertStringContainsString("'width'=>360,'height'=>640",$producer);
  self::assertStringContainsString("'width'=>595,'height'=>842",$producer);
  self::assertStringContainsString("'width'=>612,'height'=>792",$producer);
 }
}
