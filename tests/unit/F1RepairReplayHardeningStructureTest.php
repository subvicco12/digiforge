<?php

declare(strict_types=1);

final class F1RepairReplayHardeningStructureTest
{
    public static function run(string $root): void
    {
        $path = $root . '/includes/ProductFactory/Orchestrator.php';
        $source = (string) file_get_contents($path);

        self::expect(str_contains($source, "$repairVariant=$isRepair?'repair-'.$planToken:'';"), 'Repair variant must be normalized once before asset production.');
        self::expect(str_contains($source, '$definition,$key,$sequence++,$repairVariant'), 'Every repair asset spec must receive the normalized repair variant.');
        self::expect(str_contains($source, '$packageFilename=$isRepair?\'digicraftify-product-\'.$productVersionId.\'-\'.$repairVariant.\'.zip\''), 'Repair customer ZIP filename must be run-specific.');
        self::expect(str_contains($source, "$bundleKey=$isRepair?'u3-product-review-'.$planToken:'u3-product-review';"), 'Repair review bundle must have a run-specific business identity.');
        self::expect(str_contains($source, "$bundleVersion=$isRepair?'Repair '.$planToken:'Launch 1.0';"), 'Repair review bundle version must be run-specific.');
        self::expect(str_contains($source, '$variant=sanitize_key($variant);$assetKey='), 'Asset variant must be sanitized to a guaranteed string at the production boundary.');
        self::expect(str_contains($source, '$variant=sanitize_key($variant);$spec=$this->production->createSpec'), 'Package variant must be sanitized to a guaranteed string at the package boundary.');
        self::expect(str_contains($source, "'external_publish'=>false"), 'Repair production must preserve the no-external-publish invariant.');
    }

    private static function expect(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
