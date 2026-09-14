<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class EtsyTokenRefreshStructureTest extends TestCase
{
    public function testRefreshManagerUsesOfficialRefreshGrantAndFailClosedTransportSettings(): void
    {
        $manager = file_get_contents(dirname(__DIR__, 2) . '/includes/Integrations/EtsyTokenManager.php');
        self::assertIsString($manager);
        self::assertStringContainsString("'grant_type' => 'refresh_token'", $manager);
        self::assertStringContainsString("'client_id' => \$keystring", $manager);
        self::assertStringContainsString("'refresh_token' => \$refreshToken", $manager);
        self::assertStringContainsString("'Content-Type' => 'application/x-www-form-urlencoded'", $manager);
        self::assertStringContainsString("'redirection' => 0", $manager);
        self::assertStringContainsString("'reject_unsafe_urls' => true", $manager);
        self::assertStringContainsString("'sslverify' => true", $manager);
        self::assertStringContainsString("\$oauth['refresh_mode'] = 'just_in_time';", $manager);
        self::assertStringContainsString('REFRESH_SKEW_SECONDS = 120', $manager);
        self::assertStringNotContainsString('setEnabled(', $manager);
    }

    public function testConnectionTesterRefreshesBeforeAuthenticatedEtsyCheckAndRetriesOne401(): void
    {
        $tester = file_get_contents(dirname(__DIR__, 2) . '/includes/Integrations/ConnectionTester.php');
        self::assertIsString($tester);
        self::assertStringContainsString('new EtsyTokenManager()', $tester);
        self::assertStringContainsString('accessToken($integrationId)', $tester);
        self::assertStringContainsString('accessToken($integrationId, true)', $tester);
        self::assertStringContainsString('ETSY_USER_ME_URL', $tester);
        self::assertStringContainsString("'Authorization' => 'Bearer ' . \$access", $tester);
    }
}
