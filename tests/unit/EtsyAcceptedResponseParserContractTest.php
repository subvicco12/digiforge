<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class EtsyAcceptedResponseParserContractTest extends TestCase
{
    public function testParserExtractsOnlyBoundedListingIdentity(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyAcceptedResponseParser.php');
        foreach(['listing_id','1048576','raw_body_returned','hash_equals($existingReference,$reference)'] as $needle) self::assertStringContainsString($needle,$s);
        foreach(['wp_remote_','CredentialVault','Authorization','access_token','refresh_token'] as $needle) self::assertStringNotContainsString($needle,$s);
    }

    public function testExecutorFeedsParsedReferenceIntoExistingLifecycleShape(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyControlledHttpExecutor.php');
        self::assertStringContainsString('EtsyAcceptedResponseParser::parse',$s);
        self::assertStringContainsString("'external_reference'=>\$externalReference",$s);
        self::assertStringContainsString("'response_body_returned'=>false",$s);
        self::assertStringContainsString('wp_remote_retrieve_body($response)',$s);
    }
}
