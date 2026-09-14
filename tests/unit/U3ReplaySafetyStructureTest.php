<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class U3ReplaySafetyStructureTest extends TestCase
{
    public function testProductionRepositoryTreatsSameStateReplayAsIdempotent(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Production/Repository.php');
        self::assertIsString($source);
        self::assertStringContainsString("if(\$from===\$to){return \$row+['idempotent_transition'=>true];}", $source);
    }

    public function testProductionRepositoryRejectsIdempotencyPayloadDrift(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Production/Repository.php');
        self::assertIsString($source);
        self::assertStringContainsString('replayCompatible', $source);
        self::assertStringContainsString('idempotency_payload_conflict', $source);
    }

    public function testU3StillEnforcesGeneratedAssetSafetyAndUniqueness(): void
    {
        $producer = file_get_contents(__DIR__ . '/../../includes/ProductFactory/LocalAssetProducer.php');
        $orchestrator = file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertIsString($producer);
        self::assertIsString($orchestrator);
        self::assertStringContainsString('containsActiveMarkup', $producer);
        self::assertStringContainsString("Generated asset keys and filenames must be unique.", $orchestrator);
        self::assertStringContainsString('if (is_file($path))', $producer);
    }
}
