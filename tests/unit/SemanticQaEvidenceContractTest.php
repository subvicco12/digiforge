<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class SemanticQaEvidenceContractTest extends TestCase {
 public function test_semantic_qa_receives_deterministic_file_identity_evidence():void {
  $source=(string)file_get_contents(__DIR__.'/../../includes/ProductFactory/SemanticQa.php');
  self::assertStringContainsString("'byte_size' => (int) (\$file['byte_size'] ?? 0)",$source);
  self::assertStringContainsString("'checksum_sha256' => sanitize_text_field",$source);
  self::assertStringContainsString('Produced asset inventory, checksums and content samples',$source);
 }
}
