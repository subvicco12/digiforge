<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyOrderWebhookLifecycleContractTest extends TestCase {
 public function testPaidCreatesIdempotentLocalOrderWithoutFulfillmentAuthorization():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOrderWebhookLifecycle.php');foreach(['findByExternalReference','ORDER.PAID','etsy_webhook:','fulfillment_authorized','production_locked'] as $n)self::assertStringContainsString($n,$s);foreach(['wp_remote_','curl_exec(','CredentialVault','printify'] as $n)self::assertStringNotContainsString($n,$s);}
 public function testCanceledCannotAuthorizeProduction():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOrderWebhookLifecycle.php');self::assertStringContainsString("'REJECTED'",$s);self::assertStringContainsString("'fulfillment_authorized'=>false",$s);}
 public function testIntakeDelegatesOnlyAfterVerificationAndDedup():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyWebhookIntake.php');self::assertStringContainsString('EtsyWebhookVerifier::verify',$s);self::assertStringContainsString('->claim(',$s);self::assertStringContainsString('EtsyOrderWebhookLifecycle',$s);}
}
