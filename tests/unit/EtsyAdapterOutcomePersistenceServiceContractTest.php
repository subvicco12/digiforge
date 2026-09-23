<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyAdapterOutcomePersistenceServiceContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyAdapterOutcomePersistenceService.php');
    }

    public function testOutcomeRequiresSentOperationAndNormalizer(): void
    {
        $source=$this->source();
        self::assertStringContainsString('EtsyOperationLifecycle::SENT',$source);
        self::assertStringContainsString('EtsyAdapterOutcome::normalize',$source);
        self::assertStringContainsString('operation_not_sent',$source);
    }

    public function testSuccessReferenceIsPassedToLifecycleTransition(): void
    {
        $source=$this->source();
        self::assertStringContainsString('EtsyOperationLifecycle::CONFIRMED_SUCCESS',$source);
        self::assertStringContainsString("'external_reference'",$source);
        self::assertStringContainsString('transition($operationId,$state,$externalReference)',$source);
    }

    public function testBoundaryContainsNoExternalExecutionPrimitive(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'adapter_invoked'=>false",$source);
        self::assertStringContainsString("'external_execution_performed'=>false",$source);
        foreach(['->execute(','wp_remote_','curl_exec(','api.etsy','etsy.com'] as $needle) {
            self::assertStringNotContainsString($needle,$source);
        }
    }
}
