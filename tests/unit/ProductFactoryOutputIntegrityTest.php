<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductFactoryOutputIntegrityTest extends TestCase{
 public function test_pdf_page_breaks_and_structured_evidence_are_normalized():void{
  $producer=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/LocalAssetProducer.php');
  $orchestrator=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/Orchestrator.php');
  self::assertStringContainsString('[[PAGE_BREAK',$producer);
  self::assertStringContainsString('preg_split',$producer);
  self::assertStringContainsString('normalizeGeneratedEvidence',$orchestrator);
  self::assertStringContainsString("hash('sha256'",$orchestrator);
  self::assertStringContainsString('\$a[\'content\']=\$decoded',$orchestrator);
  self::assertStringNotContainsString('GENERATED_AFTER_FINAL_EXPORT',$orchestrator);
 }
}
