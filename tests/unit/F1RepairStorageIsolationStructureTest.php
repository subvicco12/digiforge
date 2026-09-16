<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class F1RepairStorageIsolationStructureTest extends TestCase
{
    public function testRepairVariantIsUsedForPhysicalAssetStorage(): void
    {
        $producer = file_get_contents(__DIR__ . '/../../includes/ProductFactory/LocalAssetProducer.php');
        $orchestrator = file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertIsString($producer);
        self::assertIsString($orchestrator);
        self::assertStringContainsString("string \$storageVariant = ''", $producer);
        self::assertStringContainsString("(\$storageVariant !== '' ? '/' . \$storageVariant : '')", $producer);
        self::assertStringContainsString("->write(\$versionId,\$filename,\$format,\$definition['content']??(\$definition['pages']??''),\$variant)", $orchestrator);
        self::assertStringContainsString("->package(\$productVersionId,\$packageInput,\$packageFilename,\$repairVariant)", $orchestrator);
    }

    public function testReplayCannotSilentlyReuseDifferentBytes(): void
    {
        $producer = file_get_contents(__DIR__ . '/../../includes/ProductFactory/LocalAssetProducer.php');
        self::assertIsString($producer);
        self::assertStringContainsString("hash('sha256', \$bytes)", $producer);
        self::assertStringContainsString('asset_replay_conflict', $producer);
        self::assertStringContainsString('Existing generated asset differs from replay payload.', $producer);
    }

    public function testCustomerFacingFilenameRemainsIndependentOfStorageVariant(): void
    {
        $producer = file_get_contents(__DIR__ . '/../../includes/ProductFactory/LocalAssetProducer.php');
        self::assertIsString($producer);
        self::assertStringContainsString("\$name = basename((string) (\$asset['filename'] ?? ''));", $producer);
        self::assertStringContainsString("\$zip->addFile(\$source, \$name)", $producer);
    }
}
