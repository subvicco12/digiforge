<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyTokenLifecycleContractTest extends TestCase
{
    public function testExpiredObservedConnectionNeverAuthorizesUseOrRenewal(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/Listings/EtsyTokenLifecycle.php';
        foreach ([true, false] as $refreshPresent) {
            $result = \DigiForge\Listings\EtsyTokenLifecycle::evaluate([
                'access_token_present' => true,
                'refresh_token_present' => $refreshPresent,
                'access_expires_at' => 1000,
            ], 1001);
            self::assertSame($refreshPresent ? 'REFRESH_REQUIRED' : 'REAUTHORIZE_REQUIRED', $result['state']);
            self::assertFalse($result['access_token_usable']);
            self::assertFalse($result['token_request_permitted']);
            self::assertFalse($result['adapter_invoked']);
            self::assertFalse($result['external_execution_performed']);
        }
    }

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
