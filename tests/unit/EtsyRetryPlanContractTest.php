<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyRetryPlanContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyRetryPlan.php');
    }

    public function testContractIsLocalOnly(): void
    {
        $source=$this->source();
        foreach(['wp_remote_','curl_exec(','etsy.com','api.etsy','$wpdb','->insert(','->update(','->execute(','ExecutionNonceLedger::consume'] as $needle) {
            self::assertStringNotContainsString($needle,$source);
        }
        self::assertStringContainsString("'adapter_invoked' => false",$source);
        self::assertStringContainsString("'external_execution_performed' => false",$source);
    }

    public function testRetryRequiresConfirmedFailure(): void
    {
        self::assertStringContainsString('EtsyOperationLifecycle::CONFIRMED_FAILURE',$this->source());
    }

    public function testRetryCannotReuseConsumedAuthorization(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'new_authorization_required' => true",$source);
        self::assertStringContainsString("'reuse_prior_authorization' => false",$source);
        self::assertStringContainsString("'prior_authorization_hash'",$source);
    }

    public function testRetryMustBeANewOperationWithNewIdempotencyIdentity(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'new_operation_required' => true",$source);
        self::assertStringContainsString('strcasecmp($oldKey, $newIdempotencyKey) === 0',$source);
        self::assertStringContainsString("'reuse_prior_idempotency_key' => false",$source);
    }

    public function testOperationIdRejectsBooleanCoercion(): void
    {
        $source=$this->source();
        self::assertStringContainsString('is_int($rawId)',$source);
        self::assertStringContainsString("preg_match('/^[1-9][0-9]*$/', ".'$rawId'.")",$source);
    }
}
