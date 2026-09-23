<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyControlledHttpExecutorContractTest extends TestCase
{
    private function source(): string { return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyControlledHttpExecutor.php'); }

    public function testExecutorRechecksLiveInterlockBeforeCredentialRetrieval(): void
    {
        $s=$this->source();
        self::assertLessThan(strpos($s,'EtsyScopedCredentialRetriever'),strpos($s,'EtsyLiveTransportInterlock::authorize'));
        self::assertStringContainsString("'ETSY_LIVE_TRANSPORT_AUTHORIZED'",$s);
        self::assertStringContainsString("'network_request_permitted']??null)!==true",$s);
    }

    public function testCredentialIsConsumedOnlyAroundInjectedSender(): void
    {
        $s=$this->source();
        foreach(['->consume(','Authorization','Bearer ','wp_remote_request','https://openapi.etsy.com/v3','$sender(',"'external_request_attempted'=>true"] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
        self::assertStringContainsString("'credential_material_exposed'=>false",$s);
        self::assertStringContainsString("'authorization_header_returned'=>false",$s);
    }

    public function testAmbiguousTransportIsClassifiedWithoutRetrying(): void
    {
        $s=$this->source();
        self::assertStringContainsString("'transport_state'=>'NO_RESPONSE'",$s);
        self::assertStringContainsString('EtsyHttpOutcome::classify',$s);
        self::assertStringNotContainsString('sleep(',$s);
        self::assertStringNotContainsString('retry',$s);
    }
}
