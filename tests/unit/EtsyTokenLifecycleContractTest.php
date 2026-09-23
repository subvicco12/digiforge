<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyTokenLifecycleContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyTokenLifecycle.php');
    }

    public function testLifecycleDistinguishesCurrentRefreshAndReauthorization(): void
    {
        $s=$this->source();
        foreach(['ACCESS_TOKEN_CURRENT','REFRESH_REQUIRED','REAUTHORIZE_REQUIRED','REFRESH_SKEW_SECONDS'] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testTokenMaterialAndRequestsRemainUnavailable(): void
    {
        $s=$this->source();
        foreach([
            "'token_material_exposed'=>false",
            "'token_request_permitted'=>false",
            "'adapter_invoked'=>false",
            "'external_execution_performed'=>false"
        ] as $needle) self::assertStringContainsString($needle,$s);
        self::assertStringNotContainsString("'access_token'=>",$s);
        self::assertStringNotContainsString("'refresh_token'=>",$s);
    }

    public function testContractContainsNoHttpPersistenceOrCredentialAccess(): void
    {
        $s=$this->source();
        foreach(['wp_remote_','curl_exec(','api.etsy','etsy.com','$wpdb','storeSecret(','CredentialVault','->execute('] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
