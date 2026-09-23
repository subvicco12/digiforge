<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyHttpRequestPlanContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyHttpRequestPlan.php');
    }

    public function testPlanBindsConsumedPermitAndCanonicalPayloadFingerprint(): void
    {
        $s=$this->source();
        foreach(['ETSY_ADAPTER_INVOCATION_PLANNED','ADAPTER_CALL_PERMITTED','nonce_consumed','EtsyRequestFingerprint::fromPayload','ETSY_HTTP_REQUEST_PLANNED'] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testRequestMetadataIsBoundedAndSecretHeadersAreForbidden(): void
    {
        $s=$this->source();
        self::assertStringContainsString("['accept','content-type','idempotency-key']",$s);
        self::assertStringContainsString("'authorization_header_permitted'=>false",$s);
        self::assertStringContainsString("'network_request_permitted'=>false",$s);
        self::assertStringContainsString("'redirects_permitted'=>false",$s);
        self::assertStringContainsString("'ssl_verification_required'=>true",$s);
    }

    public function testPlanContainsNoNetworkCredentialOrPersistencePrimitive(): void
    {
        $s=$this->source();
        foreach(['wp_remote_','curl_exec(','api.etsy','etsy.com','$wpdb','CredentialVault','storeSecret(','Bearer ','->execute('] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
