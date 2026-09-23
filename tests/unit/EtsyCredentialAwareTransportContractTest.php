<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyCredentialAwareTransportContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyCredentialAwareTransport.php');
    }

    public function testTransportRequiresNetworkDisabledPlanAndMatchingCredentialScope(): void
    {
        $s=$this->source();
        foreach(['ETSY_HTTP_REQUEST_PLANNED','ETSY_SCOPED_CREDENTIAL_ACCESS_AUTHORIZED','operation_id','integration_id',"'credential_name']??'')!=='access_token'",'EtsyCredentialEnvelope'] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testCredentialIsConsumedWithoutReturningTokenMaterial(): void
    {
        $s=$this->source();
        self::assertStringContainsString('->consume(',$s);
        self::assertStringContainsString("'credential_material_returned'=>false",$s);
        self::assertStringContainsString("'authorization_header_constructed'=>false",$s);
        self::assertStringContainsString("'network_request_permitted'=>false",$s);
        self::assertStringContainsString("'network_request_attempted'=>false",$s);
        self::assertStringContainsString("'external_execution_performed'=>false",$s);
    }

    public function testBoundaryContainsNoHttpOrPersistencePrimitive(): void
    {
        $s=$this->source();
        foreach(['wp_remote_','curl_exec(','api.etsy','etsy.com','$wpdb','CredentialVault','storeSecret(','Logger::','error_log('] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
