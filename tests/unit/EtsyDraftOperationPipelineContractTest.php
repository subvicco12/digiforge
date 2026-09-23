<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyDraftOperationPipelineContractTest extends TestCase
{
    private function source(): string { return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyDraftOperationPipeline.php'); }

    public function testDraftOperationFeedsCanonicalControlledTransport(): void
    {
        $s=$this->source();
        self::assertStringContainsString('EtsyControlledTransportOrchestrator',$s);
        self::assertStringContainsString('$operationForTransport['payload']=$payload',$s);
        self::assertStringContainsString('->prepare(',$s);
    }

    public function testPipelineCannotEnableNetworkOrPublish(): void
    {
        $s=$this->source();
        foreach(["'network_request_permitted']??null)!==false","'external_execution_performed']??null)!==false","'publish_permitted']??null)!==false","'network_request_permitted'=>false","'external_execution_performed'=>false","'publish_permitted'=>false"] as $needle) self::assertStringContainsString($needle,$s);
    }

    public function testPipelineContainsNoExternalPrimitive(): void
    {
        $s=$this->source();
        foreach(['wp_remote_','curl_exec(','EtsyScopedCredentialRetriever','CredentialVault','EtsyOperationRepository','api.etsy','openapi.etsy'] as $needle) self::assertStringNotContainsString($needle,$s);
    }
}
