<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class EtsyRateLimitReconciliationContractTest extends TestCase
{
    public function testRateLimitMetadataCannotScheduleRetry(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyRateLimitMetadata.php');
        foreach(['retry-after','x-limit-per-second','x-remaining-this-second','x-limit-per-day','x-remaining-today','automatic_retry_permitted','retry_scheduled'] as $needle) self::assertStringContainsString($needle,$s);
        self::assertStringNotContainsString('wp_schedule_',$s);
        self::assertStringNotContainsString('as_schedule_',$s);
    }

    public function testUnknownReconciliationIsLocalAndNoRetry(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationService.php');
        foreach(['EtsyReconciliationPlan::build','EtsyOperationLifecycle::RECONCILIATION','provider_lookup_required','external_retry_permitted','network_request_permitted'] as $needle) self::assertStringContainsString($needle,$s);
        foreach(['wp_remote_','curl_exec(','CredentialVault','openapi.etsy'] as $needle) self::assertStringNotContainsString($needle,$s);
    }

    public function testExecutorReturnsSanitizedRateLimitMetadata(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyControlledHttpExecutor.php');
        self::assertStringContainsString('EtsyRateLimitMetadata::fromHeaders',$s);
        self::assertStringNotContainsString('(array)wp_remote_retrieve_headers',$s);
        self::assertStringContainsString("'rate_limit'=>\$rateLimit",$s);
    }
}
