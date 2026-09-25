<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FrontendResearchPreflightStructureTest extends TestCase
{
    public function test_frontend_system_controls_render_research_preflight_without_rest_dependency(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Portal/FrontendControls.php');
        self::assertIsString($source);
        self::assertStringContainsString('ResearchActivationPreflight', $source);
        self::assertStringContainsString('Research Activation Preflight', $source);
        self::assertStringContainsString('Network requests performed: NO', $source);
        self::assertStringContainsString('External actions performed: NO', $source);
        self::assertStringContainsString("['blockers']", $source);
        self::assertStringNotContainsString('/launch/research/preflight', $source);
    }
}
