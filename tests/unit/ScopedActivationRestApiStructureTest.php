<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScopedActivationRestApiStructureTest extends TestCase
{
    public function testStage4AndStage5ActivationRoutesAreScopedAndNonExecuting(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/REST/Controller.php');
        self::assertIsString($source);

        self::assertStringContainsString("'/activations'", $source);
        self::assertStringContainsString("'/activations/printify'", $source);
        self::assertStringContainsString("'/activations/etsy-draft'", $source);
        self::assertStringContainsString('PrintifyActivationPreflight', $source);
        self::assertStringContainsString('EtsyDraftActivationPreflight', $source);
        self::assertStringContainsString('Settings::activatePrintify()', $source);
        self::assertStringContainsString('Settings::activateEtsyDraft()', $source);
        self::assertStringContainsString("'external_actions_performed' => false", $source);
        self::assertStringContainsString("permission_callback' => [\$this, 'can_manage']", $source);
    }
}
