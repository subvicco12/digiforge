<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class EtsyOAuthStructureTest extends TestCase
{
    public function testEtsyOAuthUsesPkceStateAndFixedEndpoints(): void
    {
        $oauth = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/EtsyOAuth.php');

        self::assertStringContainsString('https://www.etsy.com/oauth/connect', $oauth);
        self::assertStringContainsString('https://api.etsy.com/v3/public/oauth/token', $oauth);
        self::assertStringContainsString("'code_challenge_method' => 'S256'", $oauth);
        self::assertStringContainsString("'state' => \$state", $oauth);
        self::assertStringContainsString("'code_verifier' => \$verifier", $oauth);
        self::assertStringContainsString('random_bytes(32)', $oauth);
        self::assertStringContainsString('random_bytes(64)', $oauth);
        self::assertStringContainsString('CredentialVault::encrypt($verifier', $oauth);
        self::assertStringContainsString('delete_transient($transientKey)', $oauth);
    }

    public function testEtsyOAuthStoresTokensOnlyInCredentialVault(): void
    {
        $oauth = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/EtsyOAuth.php');

        self::assertStringContainsString("storeSecret(\$integrationId, 'access_token'", $oauth);
        self::assertStringContainsString("storeSecret(\$integrationId, 'refresh_token'", $oauth);
        self::assertStringNotContainsString("'access_token' => \$accessToken", $oauth);
        self::assertStringNotContainsString("'refresh_token' => \$refreshToken", $oauth);
        self::assertStringContainsString("'refresh_mode' => 'just_in_time'", $oauth);
        self::assertStringContainsString("'enabled' => false", $oauth);
    }

    public function testEtsyOAuthUsesFailClosedHttpSettingsAndNoBackgroundSchedule(): void
    {
        $oauth = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/EtsyOAuth.php');

        self::assertStringContainsString("'redirection' => 0", $oauth);
        self::assertStringContainsString("'reject_unsafe_urls' => true", $oauth);
        self::assertStringContainsString("'sslverify' => true", $oauth);
        self::assertStringContainsString('wp_remote_post(', $oauth);
        self::assertStringNotContainsString('wp_schedule_event', $oauth);
        self::assertStringNotContainsString('cron_schedules', $oauth);
    }

    public function testEtsyOAuthIsRegisteredAndAddsConnectControl(): void
    {
        $plugin = (string) file_get_contents(__DIR__ . '/../../includes/Core/Plugin.php');
        $oauth = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/EtsyOAuth.php');

        self::assertStringContainsString('DigiForge\\Integrations\\EtsyOAuth', $plugin);
        self::assertStringContainsString('admin_post_digiforge_etsy_oauth_start', $oauth);
        self::assertStringContainsString('admin_post_digiforge_etsy_oauth_callback', $oauth);
        self::assertStringContainsString('Connect Etsy', $oauth);
        self::assertStringContainsString('Reconnect Etsy', $oauth);
    }
}
