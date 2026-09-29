<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class FinalReleaseDocumentationCurrentTest extends TestCase {
 public function testOperatorDocumentationMatchesCertifiedRuntimeMetadata():void {
  $readme=file_get_contents(__DIR__.'/../../README.md');$plan=file_get_contents(__DIR__.'/../../docs/releases/V1.0.78_CONTROLLED_DEPLOYMENT_PLAN.md');
  self::assertStringContainsString('current certified schema is version **23**',$readme);
  self::assertStringNotContainsString('current schema is version **14**',$readme);
  self::assertStringContainsString('Candidate: DigiForge 1.0.78',$plan);
  self::assertStringContainsString('Expected schema after migration: 23',$plan);
  self::assertStringContainsString('STOP ALL ON',$plan);
  self::assertStringContainsString('production_activation_authorized=false',$plan);
  self::assertStringContainsString('must not publish Etsy listings',$plan);
  self::assertStringContainsString('submit POD production',$plan);
 }
}
