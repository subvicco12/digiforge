<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsySellerTaxonomyClientContractTest extends TestCase {
 public function testReaderIsGetOnlyAndUsesOfficialSellerTaxonomyEndpoint():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Integrations/EtsySellerTaxonomyClient.php');foreach(['seller-taxonomy/nodes','wp_remote_get','redirection'=>0,'reject_unsafe_urls'=>true,'sslverify'=>true,'EtsySellerTaxonomyVerifier::verify','ConnectionTester'] as $n)self::assertStringContainsString($n,$s);foreach(['wp_remote_post','wp_remote_request','listings/','publish'] as $n)self::assertStringNotContainsString($n,$s);}
 public function testCredentialsAreSingleUseAndCleared():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Integrations/EtsyReadOnlyCredentialEnvelope.php');foreach(['private bool $used=false','access_token','keystring','shared_secret',"$this->apiKey=''", "$this->access=''"] as $n)self::assertStringContainsString($n,$s);}
}
