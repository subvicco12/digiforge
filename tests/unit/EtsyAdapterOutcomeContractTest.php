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

    public function testTerminalEvidenceMustBeStringsBeforeNormalization(): void
    {
        $source=$this->source();
        self::assertStringContainsString('!is_string($referenceRaw)',$source);
        self::assertStringContainsString('!is_string($categoryRaw)',$source);
        self::assertStringContainsString('!is_string($codeRaw)',$source);
    }

    public function testSuccessRequiresBoundedExternalReference(): void
    {
        $source=$this->source();
        self::assertStringContainsString('MAX_EXTERNAL_REFERENCE_LENGTH = 191',$source);
        self::assertStringContainsString('strlen($reference) > self::MAX_EXTERNAL_REFERENCE_LENGTH',$source);
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
        self::assertStringContainsString('MAX_FAILURE_EVIDENCE_LENGTH = 64',$source);
        self::assertStringContainsString('strlen($category) > self::MAX_FAILURE_EVIDENCE_LENGTH',$source);
        self::assertStringContainsString('strlen($code) > self::MAX_FAILURE_EVIDENCE_LENGTH',$source);
        self::assertStringContainsString('EtsyOperationLifecycle::retryPermitted',$source);
    }
}
