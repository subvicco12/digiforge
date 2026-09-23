<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyCredentialEnvelopeContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyCredentialEnvelope.php');
    }

    public function testEnvelopeIsSingleUseAndOperationScoped(): void
    {
        $s=$this->source();
        foreach(['ETSY_SCOPED_CREDENTIAL_ACCESS_AUTHORIZED','single_use','integrationId','operationId','private bool $consumed=false','$this->consumed=true',"$this->token=''"] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testNormalRepresentationsAreRedacted(): void
    {
        $s=$this->source();
        foreach(['JsonSerializable','jsonSerialize','__toString','__debugInfo',"'redacted'=>true",'[REDACTED]'] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
        self::assertStringNotContainsString('return $this->token',$s);
    }

    public function testEnvelopeContainsNoPersistenceLoggingOrNetworkPrimitive(): void
    {
        $s=$this->source();
        foreach(['CredentialVault','EtsyTokenManager','wp_remote_','curl_exec(','api.etsy','etsy.com','$wpdb','Logger::','error_log(','update_option(','set_transient('] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
