<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FrontendAiCredentialRepairTest extends TestCase
{
    public function test_ai_credential_repair_is_frontend_gated_and_never_changes_activation_state(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Portal/Portal.php');
        self::assertIsString($source);
        self::assertStringContainsString('SAVE_AI_SECRET_ACTION', $source);
        self::assertStringContainsString('public function saveAiSecret()', $source);
        self::assertStringContainsString("(string) \$row['provider'] !== 'ai'", $source);
        self::assertStringContainsString("(string) \$row['environment'] !== 'production'", $source);
        self::assertStringContainsString("storeSecret(\$integrationId, 'api_key', \$value)", $source);
        self::assertStringContainsString('No activation switch was changed.', $source);
        self::assertStringNotContainsString("Settings::set('ai'", $source);
        self::assertStringNotContainsString("Settings::set('research'", $source);
    }

    public function test_frontend_credential_form_does_not_render_the_secret_after_submission(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Portal/Portal.php');
        self::assertIsString($source);
        self::assertStringContainsString('type="password"', $source);
        self::assertStringContainsString('autocomplete="new-password"', $source);
        self::assertStringNotContainsString('echo esc_html($value)', $source);
    }
}
