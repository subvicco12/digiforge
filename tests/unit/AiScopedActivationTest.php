<?php
declare(strict_types=1);

use DigiForge\Launch\AiActivationPreflight;
use PHPUnit\Framework\TestCase;

final class AiScopedActivationTest extends TestCase
{
    public function testAiPreflightRequiresResearchStageAndCredentialEvidence(): void
    {
        $ready = AiActivationPreflight::summarize([
            'stop_all' => false, 'activation_authorized' => true, 'automation_armed' => true,
            'research_authorized' => true, 'research_effective' => true, 'ai_configured' => true,
            'ai_authorized' => false, 'ai_effective' => false, 'connector_ready' => true,
            'credential_present' => true, 'credential_decryptable' => true,
        ]);
        self::assertSame('READY_FOR_CONTROLLED_AI_ACTIVATION', $ready['status']);
        self::assertFalse($ready['network_requests_performed']);
        self::assertFalse($ready['external_actions_performed']);

        $blocked = AiActivationPreflight::summarize([
            'stop_all' => false, 'activation_authorized' => true, 'automation_armed' => true,
            'research_authorized' => true, 'research_effective' => true, 'ai_configured' => true,
            'ai_authorized' => false, 'ai_effective' => false, 'connector_ready' => true,
            'credential_present' => true, 'credential_decryptable' => false,
        ]);
        self::assertSame('BLOCKED', $blocked['status']);
        self::assertContains('credential_decryptable', $blocked['blockers']);
    }

    public function testAiAuthorizationIsSeparateAndLaterCapabilitiesStayFailClosed(): void
    {
        $config = file_get_contents(dirname(__DIR__, 2) . '/includes/Core/Config.php');
        $settings = file_get_contents(dirname(__DIR__, 2) . '/includes/Core/Settings.php');
        $portal = file_get_contents(dirname(__DIR__, 2) . '/includes/Portal/FrontendControls.php');
        self::assertIsString($config); self::assertIsString($settings); self::assertIsString($portal);
        self::assertStringContainsString("'ai_activation_authorized'", $config);
        self::assertStringContainsString('public static function activateAi(): bool', $settings);
        self::assertStringContainsString("in_array(\$switch, ['research', 'ai'], true)", $settings);
        self::assertStringContainsString("'ai_activation_authorized' => false", $settings);
        self::assertStringContainsString('READY_FOR_CONTROLLED_AI_ACTIVATION', $portal);
        self::assertStringContainsString('Authorize AI Capability', $portal);
        self::assertStringContainsString('No provider request was performed by activation.', $portal);
    }
}
