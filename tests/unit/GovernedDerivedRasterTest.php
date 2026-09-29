<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class GovernedDerivedRasterTest extends TestCase
{
    public function test_raster_derivation_remains_fail_closed_and_lineage_preserving(): void
    {
        $service=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/DerivedRasterService.php');
        $rest=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/ProductionController.php');

        self::assertStringContainsString("!== 'APPROVED'",$service);
        self::assertStringContainsString("'image/svg+xml'",$service);
        self::assertStringContainsString('hash_file',$service);
        self::assertStringContainsString("'asset_type' => 'listing_image'",$service);
        self::assertStringContainsString("'format' => 'png'",$service);
        self::assertStringContainsString("'approval_inherited' => false",$service);
        self::assertStringContainsString("'external_action_performed' => false",$service);
        self::assertStringContainsString("'release_bundle_mutated' => false",$service);
        self::assertStringContainsString('addRevision',$service);
        self::assertStringNotContainsString('validateBundle',$service);
        self::assertStringNotContainsString('linkAsset',$service);
        self::assertStringContainsString('/production/revisions/(?P<id>\\d+)/rasterize',$rest);
        self::assertStringContainsString('Idempotency-Key',$rest);
    }
}
