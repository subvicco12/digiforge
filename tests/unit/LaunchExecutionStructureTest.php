<?php

declare(strict_types=1);

namespace DigiForge\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LaunchExecutionStructureTest extends TestCase
{
    public function testLaunchControllerIsRegisteredAndPreservesApprovalGate(): void
    {
        $plugin = file_get_contents(__DIR__ . '/../../includes/Core/Plugin.php');
        $controller = file_get_contents(__DIR__ . '/../../includes/REST/LaunchController.php');
        $engine = file_get_contents(__DIR__ . '/../../includes/Launch/ExecutionEngine.php');
        $client = file_get_contents(__DIR__ . '/../../includes/Launch/OpenAIClient.php');
        self::assertIsString($plugin); self::assertIsString($controller); self::assertIsString($engine); self::assertIsString($client);
        self::assertStringContainsString('new LaunchController()', $plugin);
        self::assertStringContainsString('/launch/research', $controller);
        self::assertStringContainsString('/launch/candidates/(?P<id>\\d+)/develop', $controller);
        self::assertStringContainsString("get_header('Idempotency-Key')", $controller);
        self::assertStringContainsString("array_key_exists('_idempotency_key', \$params)", $controller);
        self::assertStringContainsString("\$params['_idempotency_key']", $controller);
        self::assertStringContainsString("unset(\$payload['_idempotency_key'])", $controller);
        self::assertStringContainsString('! is_string($bodyKey)', $controller);
        self::assertStringContainsString('The _idempotency_key JSON field must be a string.', $controller);
        self::assertStringContainsString('Idempotency-Key header or _idempotency_key JSON field is required.', $controller);
        self::assertStringContainsString('REVIEW_APPROVED', $engine);
        self::assertStringContainsString('approval_required', $engine);
        self::assertStringContainsString("Settings::is_enabled('research')", $engine);
        self::assertStringContainsString("Settings::is_enabled('product_development')", $engine);
        self::assertStringContainsString("'tools'", $client);
        self::assertStringContainsString("'web_search'", $client);
        self::assertStringContainsString('CredentialVault::decrypt', $client);
        self::assertMatchesRegularExpression('/\\$status\\s*===\\s*429/', $client);
        self::assertStringContainsString('insufficient_quota', $client);
        self::assertStringContainsString('billing_hard_limit_reached', $client);
        self::assertStringContainsString('digiforge_launch_ai_quota', $client);
        self::assertStringContainsString('digiforge_launch_ai_rate_limited', $client);
        self::assertMatchesRegularExpression("/wp_remote_retrieve_header\\(\\s*\\$response\\s*,\\s*'x-request-id'\\s*\\)/", $client);
        self::assertStringNotContainsString('api_key' . ' => ', $client);
    }
}
