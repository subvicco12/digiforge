<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3RepairCycleStructureTest extends TestCase
{
    public function testRepairRunUsesUniqueKeyAndCarriesItToWorker(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/ProductFactory/ApprovalAutomation.php');
        self::assertIsString($source);
        self::assertStringContainsString("u3-repair-candidate-", $source);
        self::assertStringContainsString("add_action(self::HOOK, [$this, 'run'], 10, 3)", $source);
        self::assertStringContainsString("'external_actions' => false", $source);
    }

    public function testDevelopmentIdentityIsStableAcrossRepairRuns(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertIsString($source);
        self::assertStringContainsString("$developmentKey = 'u3-auto-candidate-'", $source);
        self::assertStringContainsString("->develop($candidateId, ['shop' => $shop], $developmentKey)", $source);
    }

    public function testRepairPackageHasManifestAndProvenanceEvidence(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertIsString($source);
        self::assertStringContainsString('DELIVERY-MANIFEST.json', $source);
        self::assertStringContainsString('LICENSE-AND-PROVENANCE.txt', $source);
        self::assertStringContainsString("'marketing_assets_in_customer_package' => false", $source);
    }

    public function testProductionPromptRejectsFakeExternalDeliverables(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/ProductFactory/Orchestrator.php');
        self::assertIsString($source);
        self::assertStringContainsString('fake Canva links', $source);
        self::assertStringContainsString('placeholder URLs', $source);
        self::assertStringContainsString('HTML must be a complete valid document', $source);
        self::assertStringContainsString('do not market it', $source);
    }
}
