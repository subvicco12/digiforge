<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyTokenMetadataBridgeContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyTokenMetadataBridge.php');
    }

    public function testBridgeUsesOnlyPublicIntegrationMetadataAndLifecycleContract(): void
    {
        $s=$this->source();
        foreach(['Repository','->find(','secret_name','access_token','refresh_token','access_expires_at','EtsyTokenLifecycle::evaluate'] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testBridgeCannotExposeOrRefreshTokenMaterial(): void
    {
        $s=$this->source();
        self::assertStringContainsString("'token_material_exposed'=>false",$s);
        self::assertStringContainsString("'token_refresh_performed'=>false",$s);
        self::assertStringContainsString("'token_request_permitted'=>false",$s);
        self::assertStringContainsString("'external_execution_performed'=>false",$s);
    }

    public function testBridgeContainsNoCredentialDecryptionOrNetworkPrimitive(): void
    {
        $s=$this->source();
        foreach(['CredentialVault','decrypt(','EtsyTokenManager','accessToken(','wp_remote_','curl_exec(','api.etsy','etsy.com','$wpdb'] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
