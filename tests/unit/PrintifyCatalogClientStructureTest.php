<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PrintifyCatalogClientStructureTest extends TestCase
{
    private function source(): string
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyCatalogClient.php');
        self::assertIsString($source);
        return $source;
    }

    public function testClientIsReadOnlyAndOriginPinned(): void
    {
        $source=$this->source();
        self::assertStringContainsString("private const V1 = 'https://api.printify.com/v1/'",$source);
        self::assertStringContainsString("private const V2 = 'https://api.printify.com/v2/'",$source);
        self::assertStringContainsString('wp_remote_get',$source);
        self::assertStringNotContainsString('wp_remote_post',$source);
        self::assertStringNotContainsString('wp_remote_request',$source);
        self::assertStringNotContainsString('/products.json',$source);
        self::assertStringNotContainsString('/orders.json',$source);
        self::assertStringContainsString("'redirection'=>0",$source);
        self::assertStringContainsString("'reject_unsafe_urls'=>true",$source);
        self::assertStringContainsString("'sslverify'=>true",$source);
        self::assertStringContainsString("'api.printify.com'",$source);
        self::assertStringContainsString('approvedEndpoint',$source);
    }

    public function testClientRequiresBearerCredentialWithoutLoggingIt(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'Authorization'=>'Bearer '.\$token",$source);
        self::assertStringContainsString("'User-Agent'=>'DigiForge/PrintifyCatalogSync'",$source);
        self::assertStringContainsString('digiforge_printify_credentials',$source);
        self::assertStringNotContainsString('error_log',$source);
        self::assertStringNotContainsString('Logger::',$source);
    }

    public function testOnlyCatalogAndShippingRoutesAreAllowlisted(): void
    {
        $source=$this->source();
        self::assertStringContainsString('catalog/blueprints.json',$source);
        self::assertStringContainsString('/print_providers.json',$source);
        self::assertStringContainsString('/variants.json',$source);
        self::assertStringContainsString("['standard', 'priority', 'express', 'economy']",$source);
        self::assertStringContainsString('preg_match',$source);
    }

    public function testRateLimitHandlingIsBoundedAndFinal429IsExplicit(): void
    {
        $source=$this->source();
        self::assertStringContainsString('private const MAX_ATTEMPTS = 3',$source);
        self::assertStringContainsString('private const MAX_RETRY_AFTER_SECONDS = 60',$source);
        self::assertStringContainsString('$status===429',$source);
        self::assertStringContainsString("'retry-after'",$source);
        self::assertStringContainsString('digiforge_printify_rate_limit',$source);
        self::assertStringContainsString('retryAfterSeconds',$source);
    }

    public function testTransportErrorsAreSanitized(): void
    {
        $source=$this->source();
        self::assertStringContainsString('digiforge_printify_transport',$source);
        self::assertStringContainsString('Printify catalog transport failed.',$source);
        self::assertStringNotContainsString('get_error_message',$source);
    }
}
