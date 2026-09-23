<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyScopedCredentialAccessContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyScopedCredentialAccess.php');
    }

    public function testAccessIsBoundToAuthorizedAcquisitionAndOperation(): void
    {
        $s=$this->source();
        foreach(['ETSY_TOKEN_ACQUISITION_AUTHORIZED','credential_retrieval_authorized','ETSY_ADAPTER_INVOCATION_PLANNED','operation_id','integration_id'] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testScopeIsSinglePurposeAndNonPersistent(): void
    {
        $s=$this->source();
        foreach(["'credential_name'=>'access_token'","'single_use'=>true","'general_credential_access'=>false","'refresh_token_access'=>false","'credential_persistence_permitted'=>false","'credential_logging_permitted'=>false","'credential_retrieval_performed'=>false","'token_material_exposed'=>false","'network_request_permitted'=>false"] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testContractContainsNoCredentialOrNetworkImplementation(): void
    {
        $s=$this->source();
        foreach(['CredentialVault','decrypt(','EtsyTokenManager','accessToken(','wp_remote_','curl_exec(','api.etsy','etsy.com','$wpdb','Logger::'] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
