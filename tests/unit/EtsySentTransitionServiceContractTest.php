<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsySentTransitionServiceContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsySentTransitionService.php');
    }

    public function testSentRequiresValidatedExternalAttemptEvidence(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'ETSY_EXTERNAL_ATTEMPT_EVIDENCED'",$source);
        self::assertStringContainsString("sent_transition_eligible'] ?? null) !== true",$source);
        self::assertStringContainsString("external_execution_performed'] ?? null) !== true",$source);
    }

    public function testOnlyNotSentOperationCanTransition(): void
    {
        $source=$this->source();
        self::assertStringContainsString('EtsyOperationLifecycle::NOT_SENT',$source);
        self::assertStringContainsString('EtsyOperationLifecycle::SENT',$source);
        self::assertStringContainsString('operation_not_sendable',$source);
    }

    public function testServiceContainsNoExternalExecutionPrimitive(): void
    {
        $source=$this->source();
        foreach(['->execute(','wp_remote_','curl_exec(','api.etsy','etsy.com'] as $needle) {
            self::assertStringNotContainsString($needle,$source);
        }
    }
}
