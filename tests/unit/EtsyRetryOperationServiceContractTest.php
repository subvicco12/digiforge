<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyRetryOperationServiceContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyRetryOperationService.php');
    }

    public function testUsesRetryPlanAndCanonicalPayloadCreation(): void
    {
        $s=$this->source();
        self::assertStringContainsString('EtsyRetryPlan::build',$s);
        self::assertStringContainsString('createFromPayload($input,$payload)',$s);
    }

    public function testReuseAcrossRetryChainIsRejected(): void
    {
        $s=$this->source();
        self::assertStringContainsString('byKey(',$s);
        self::assertStringContainsString('idempotency_reuse',$s);
        self::assertStringContainsString('fresh_operation_required',$s);
        self::assertStringContainsString('EtsyOperationLifecycle::NOT_SENT',$s);
        self::assertStringContainsString("'reuse_prior_authorization'=>false",$s);
        self::assertStringContainsString("'reuse_prior_idempotency_key'=>false",$s);
    }

    public function testFactoryContainsNoExternalPrimitive(): void
    {
        $s=$this->source();
        foreach(['->execute(','wp_remote_','curl_exec(','api.etsy','etsy.com'] as $needle) self::assertStringNotContainsString($needle,$s);
    }
}
