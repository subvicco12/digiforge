<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyCredentialAwareTransportContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyCredentialAwareTransport.php');
    }

    public function testTransportRequiresNetworkDisabledPlanAndMatchingUnusedCredentialScope(): void
    {
        $s=$this->source();
        foreach(['ETSY_HTTP_REQUEST_PLANNED','ETSY_SCOPED_CREDENTIAL_ACCESS_AUTHORIZED','operation_id','integration_id',"'credential_name']??'')!=='access_token'","'single_use']??null)!==true","'credential_retrieval_performed']??null)!==false"] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testCredentialConsumptionIsDeferredWithoutReturningTokenMaterial(): void
    {
        $s=$this->source();
        self::assertStringNotContainsString('->consume(',$s);
        self::assertStringNotContainsString('EtsyCredentialEnvelope',$s);
        self::assertStringContainsString("'credential_retrieval_deferred'=>true",$s);
        self::assertStringContainsString("'credential_material_returned'=>false",$s);
        self::assertStringContainsString("'authorization_header_constructed'=>false",$s);
        self::assertStringContainsString("'network_request_permitted'=>false",$s);
        self::assertStringContainsString("'network_request_attempted'=>false",$s);
        self::assertStringContainsString("'external_execution_performed'=>false",$s);
    }

    public function testBoundaryContainsNoHttpCredentialRetrievalOrPersistencePrimitive(): void
    {
        $s=$this->source();
        foreach(['wp_remote_','curl_exec(','api.etsy','etsy.com','$wpdb','CredentialVault','EtsyScopedCredentialRetriever','storeSecret(','Logger::','error_log('] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
