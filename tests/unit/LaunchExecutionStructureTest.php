<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class LaunchExecutionStructureTest extends TestCase
{
    public function test_launch_controller_is_registered_and_preserves_approval_gate(): void
    {
        $plugin = file_get_contents(__DIR__ . '/../../includes/Core/Plugin.php');
        $controller = file_get_contents(__DIR__ . '/../../includes/REST/LaunchController.php');
        $engine = file_get_contents(__DIR__ . '/../../includes/Launch/ExecutionEngine.php');
        $client = file_get_contents(__DIR__ . '/../../includes/Launch/OpenAIClient.php');

        self::assertIsString($plugin);
        self::assertIsString($controller);
        self::assertIsString($engine);
        self::assertIsString($client);

        self::assertStringContainsString('new LaunchController()', $plugin);
        self::assertStringContainsString('/launch/research', $controller);
        self::assertStringContainsString('/launch/candidates/(?P<id>\\d+)/develop', $controller);
        self::assertStringContainsString('Idempotency-Key header is required', $controller);
        self::assertStringContainsString('REVIEW_APPROVED', $engine);
        self::assertStringContainsString('approval_required', $engine);
        self::assertStringContainsString("Settings::is_enabled('research')", $engine);
        self::assertStringContainsString("Settings::is_enabled('product_development')", $engine);
        self::assertStringContainsString("'tools'", $client);
        self::assertStringContainsString("'web_search'", $client);
        self::assertStringContainsString('CredentialVault::decrypt', $client);
        self::assertStringNotContainsString('api_key' . ' => ', $client);
    }
}
