<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyTokenAcquisitionGateContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyTokenAcquisitionGate.php');
    }

    public function testGateRequiresCurrentTokenMetadataAndConsumedInvocationPermit(): void
    {
        $s=$this->source();
        foreach(['ETSY_TOKEN_METADATA_EVALUATED','ETSY_ADAPTER_INVOCATION_PLANNED','ADAPTER_CALL_PERMITTED','nonce_consumed','ACCESS_TOKEN_CURRENT','access_token_usable'] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testAuthorizationDoesNotPerformCredentialOrNetworkWork(): void
    {
        $s=$this->source();
        self::assertStringContainsString("'credential_retrieval_authorized'=>true",$s);
        self::assertStringContainsString("'credential_retrieval_performed'=>false",$s);
        self::assertStringContainsString("'token_material_exposed'=>false",$s);
        self::assertStringContainsString("'token_refresh_permitted'=>false",$s);
        self::assertStringContainsString("'network_request_permitted'=>false",$s);
        self::assertStringContainsString("'external_execution_performed'=>false",$s);
    }

    public function testGateContainsNoVaultTokenManagerNetworkOrPersistencePrimitive(): void
    {
        $s=$this->source();
        foreach(['CredentialVault','decrypt(','EtsyTokenManager','accessToken(','wp_remote_','curl_exec(','api.etsy','etsy.com','$wpdb'] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
