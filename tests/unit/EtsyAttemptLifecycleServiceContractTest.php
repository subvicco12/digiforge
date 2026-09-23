<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyAttemptLifecycleServiceContractTest extends TestCase
{
    private function source(): string { return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyAttemptLifecycleService.php'); }

    public function testActualAttemptEvidenceMustPrecedeSentAndOutcomePersistence(): void
    {
        $s=$this->source();
        $e=strpos($s,'EtsyExternalAttemptEvidence::validate');
        $sent=strpos($s,'EtsySentTransitionService');
        $out=strpos($s,'EtsyAdapterOutcomePersistenceService');
        self::assertIsInt($e); self::assertIsInt($sent); self::assertIsInt($out);
        self::assertLessThan($sent,$e);
        self::assertLessThan($out,$sent);
        self::assertStringContainsString("'network_request_attempted']??null)!==true",$s);
        self::assertStringContainsString("'external_execution_performed']??null)!==true",$s);
    }

    public function testAmbiguousHttpOutcomeBecomesUnknownWithoutAutomaticRetry(): void
    {
        $s=$this->source();
        self::assertStringContainsString("if (\$state==='UNKNOWN') return ['state'=>'UNKNOWN'];",$s);
        self::assertStringContainsString("'automatic_retry_permitted'=>false",$s);
        self::assertStringContainsString("'reconciliation_required'",$s);
    }

    public function testAcceptedResponseNeedsExternalReferenceBeforeConfirmedSuccess(): void
    {
        $s=$this->source();
        self::assertStringContainsString("if (\$state==='RESPONSE_ACCEPTED')",$s);
        self::assertStringContainsString("['state'=>'CONFIRMED_SUCCESS','external_reference'=>\$reference]",$s);
        self::assertStringContainsString("Accepted Etsy response requires a bounded external reference",$s);
    }

    public function testLifecycleComposerContainsNoNetworkOrCredentialPrimitive(): void
    {
        $s=$this->source();
        foreach(['wp_remote_','curl_exec(','CredentialVault','EtsyScopedCredentialRetriever','Authorization','Bearer ','api.etsy','openapi.etsy'] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
