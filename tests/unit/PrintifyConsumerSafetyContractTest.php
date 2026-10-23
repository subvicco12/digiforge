<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PrintifyConsumerSafetyContractTest extends TestCase
{
    public function testEveryIntegrationConsumerHandlesUnavailableRepositoryEvidenceBeforeArrayAccess(): void
    {
        foreach (['includes/POD/PrintifyScopedCredentialRetriever.php',
            'includes/POD/PrintifyCatalogSyncService.php', 'includes/Integrations/ConnectionTester.php'] as $file) {
            $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$file);
            self::assertMatchesRegularExpression('/find\(\$integrationId\);\s*if\s*\(is_wp_error\(\$integration\)\)/', $source, $file);
        }
    }

    public function testOnlyConfirmedMissingCredentialsPermitConnectionTesterFallback(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/includes/Integrations/ConnectionTester.php');
        self::assertStringContainsString("is_wp_error(\$value) && \$value->get_error_code() !== 'credential_not_found'", $source);
        self::assertStringContainsString("is_wp_error(\$token) && \$token->get_error_code() !== 'credential_not_found'", $source);
        $protection = strpos($source, 'PrintifyCatalogReadContract::containsProtectedMaterial($data, $token)');
        $recording = strpos($source, "$"."checked = \$this->checkHttp('printify'");
        self::assertIsInt($protection);
        self::assertIsInt($recording);
        self::assertLessThan($recording, $protection);
    }

    public function testVaultConsumersCheckSqlFailureBeforeFallbackAndClearTokensAfterThrowable(): void
    {
        foreach (['PrintifyScopedCredentialRetriever', 'PrintifyCatalogSyncService'] as $class) {
            $source = (string) file_get_contents(dirname(__DIR__, 2).'/includes/POD/'.$class.'.php');
            self::assertMatchesRegularExpression('/get_var\([^;]+;if\(!empty\(\$wpdb->last_error\)\)return new WP_Error/', $source);
            self::assertStringContainsString('catch(\\Throwable)', $source);
            self::assertStringContainsString('finally', $source);
            self::assertStringNotContainsString('getMessage()', $source);
        }
    }
}
