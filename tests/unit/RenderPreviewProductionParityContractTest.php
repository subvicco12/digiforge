<?php declare(strict_types=1); use PHPUnit\Framework\TestCase;
final class RenderPreviewProductionParityContractTest extends TestCase {
 public function testRenderCertificationRequiresBuyerPreviewToMatchProductionOutput():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/RenderEvidenceRepository.php');self::assertStringContainsString("buyer_preview_sha256",$s);self::assertStringContainsString("hash_equals(\$outputHash,\$previewHash)",$s);self::assertStringContainsString("render_preview_parity_failed",$s);self::assertStringContainsString("'buyer_preview_sha256'=>\$previewHash",$s);}
}
