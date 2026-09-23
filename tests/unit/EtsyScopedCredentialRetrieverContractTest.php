<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyScopedCredentialRetrieverContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyScopedCredentialRetriever.php');
    }

    public function testRetrieverRequiresNarrowUnusedAccessTokenScope(): void
    {
        $s=$this->source();
        foreach(['ETSY_SCOPED_CREDENTIAL_ACCESS_AUTHORIZED',"'credential_name']??'')!=='access_token'",'general_credential_access','refresh_token_access','credential_persistence_permitted','credential_logging_permitted','credential_retrieval_performed','network_request_permitted','external_execution_performed'] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testRetrieverReadsOnlyAccessTokenAndSealsOpaqueEnvelope(): void
    {
        $s=$this->source();
        self::assertStringContainsString("secret_name = %s",$s);
        self::assertStringContainsString("'access_token'",$s);
        self::assertStringContainsString('CredentialVault::decrypt',$s);
        self::assertStringContainsString('Repository::secretContext',$s);
        self::assertStringContainsString('EtsyCredentialEnvelope::seal',$s);
        self::assertStringContainsString("\$token='';",$s);
        self::assertStringNotContainsString("'refresh_token'",$s);
    }

    public function testRetrieverCannotRefreshPersistLogOrUseNetwork(): void
    {
        $s=$this->source();
        foreach(['EtsyTokenManager','storeSecret(','wp_remote_','curl_exec(','api.etsy','etsy.com','Logger::','error_log(','update_option(','set_transient('] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
