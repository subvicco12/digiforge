<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyAdapterOutcomeContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyAdapterOutcome.php');
    }

    public function testContractIsLocalOnly(): void
    {
        $source=$this->source();
        foreach(['wp_remote_','curl_exec(','etsy.com','api.etsy','$wpdb','->insert(','->update('] as $needle) {
            self::assertStringNotContainsString($needle,$source);
        }
    }

    public function testOnlyCanonicalPostSendOutcomesAreAccepted(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'CONFIRMED_SUCCESS'",$source);
        self::assertStringContainsString("'CONFIRMED_FAILURE'",$source);
        self::assertStringContainsString("'UNKNOWN'",$source);
        self::assertStringNotContainsString("'SENT' =>",$source);
        self::assertStringNotContainsString("'NOT_SENT' =>",$source);
    }

    public function testSuccessRequiresExternalReference(): void
    {
        $source=$this->source();
        self::assertStringContainsString('success_reference',$source);
        self::assertStringContainsString('external_reference',$source);
        self::assertStringContainsString("'retry_permitted' => false",$source);
    }

    public function testUnknownRequiresReconciliationAndNeverRetries(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'reconciliation_required' => true",$source);
        self::assertStringContainsString('EtsyOperationLifecycle::UNKNOWN',$source);
    }

    public function testConfirmedFailureCarriesBoundedFailureEvidence(): void
    {
        $source=$this->source();
        self::assertStringContainsString('failure_category',$source);
        self::assertStringContainsString('failure_code',$source);
        self::assertStringContainsString('EtsyOperationLifecycle::retryPermitted',$source);
    }
}
