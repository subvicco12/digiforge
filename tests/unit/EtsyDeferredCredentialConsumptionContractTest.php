<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyDeferredCredentialConsumptionContractTest extends TestCase
{
    public function testPreNetworkOrchestratorDoesNotRetrieveOrConsumeSecret(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyControlledTransportOrchestrator.php');
        self::assertStringNotContainsString('EtsyScopedCredentialRetriever',$s);
        self::assertStringContainsString('EtsyCredentialAwareTransport',$s);
    }

    public function testTransportCarriesOnlyUnusedCredentialScope(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyCredentialAwareTransport.php');
        foreach(["'credential_retrieval_performed']??null)!==false","'credential_retrieval_deferred'=>true","'credential_material_returned'=>false"] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
        self::assertStringNotContainsString('->consume(',$s);
        self::assertStringNotContainsString('EtsyScopedCredentialRetriever',$s);
    }
}
