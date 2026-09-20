<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class ProductFactoryFilenameNormalizationTest extends TestCase
{
    public function test_generated_asset_filename_is_normalized_to_declared_format(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/ProductFactory/Orchestrator.php');
        self::assertStringContainsString('$filename=$this->normalizedFilename', $source);
        self::assertStringContainsString("pathinfo(\$filename,PATHINFO_FILENAME)", $source);
        self::assertStringContainsString("return substr(\$base,0,150).'.'.\$format", $source);
        self::assertStringContainsString("sanitize_key(\$format)", $source);
    }
}
