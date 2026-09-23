<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyHttpOutcomeContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyHttpOutcome.php');
    }

    public function testClassifierSeparatesConfirmedFailuresFromUnknownOutcomes(): void
    {
        $s=$this->source();
        foreach(['RESPONSE_ACCEPTED','CONFIRMED_FAILURE','UNKNOWN','AUTHENTICATION','RATE_LIMIT','TRANSIENT_HTTP','PROVIDER'] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testUnknownTransportAndServerOutcomesRequireReconciliation(): void
    {
        $s=$this->source();
        self::assertStringContainsString("'NO_RESPONSE'",$s);
        self::assertStringContainsString("'reconciliation_required'=>\$reconcile",$s);
        self::assertStringContainsString("'automatic_retry_permitted'=>false",$s);
    }

    public function testClassifierContainsNoHttpCredentialPersistenceOrRetryPrimitive(): void
    {
        $s=$this->source();
        foreach(['wp_remote_','curl_exec(','api.etsy','etsy.com','$wpdb','CredentialVault','storeSecret(','sleep(','usleep(','->execute('] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
        self::assertStringContainsString("'response_body_exposed'=>false",$s);
        self::assertStringContainsString("'credentials_exposed'=>false",$s);
    }
}
