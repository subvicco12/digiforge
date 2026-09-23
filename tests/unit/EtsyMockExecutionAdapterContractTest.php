<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyMockExecutionAdapterContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyMockExecutionAdapter.php');
    }

    public function testMockAdapterRequiresConsumedPermitAndUsesOutcomeClassifier(): void
    {
        $s=$this->source();
        foreach(['implements EtsyExecutionAdapter','ADAPTER_CALL_PERMITTED','nonce_consumed','EtsyHttpOutcome::classify','MOCK_ONLY'] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testMockCanNeverClaimExternalRequestAttempt(): void
    {
        $s=$this->source();
        self::assertStringContainsString("'external_request_attempted'=>false",$s);
        self::assertStringContainsString("'external_execution_performed'=>false",$s);
        self::assertStringContainsString("'network_request_permitted'=>false",$s);
        self::assertStringContainsString("'credentials_exposed'=>false",$s);
    }

    public function testHarnessContainsNoNetworkCredentialOrPersistencePrimitive(): void
    {
        $s=$this->source();
        foreach(['wp_remote_','curl_exec(','api.etsy','etsy.com','$wpdb','CredentialVault','Bearer ','EtsySentTransitionService'] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
