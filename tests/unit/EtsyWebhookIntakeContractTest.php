<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyWebhookIntakeContractTest extends TestCase {
 public function testVerifierIsSignatureFirstAndReplayBounded():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyWebhookVerifier.php');foreach(['hash_hmac','hash_equals','MAX_SKEW_SECONDS','order.paid','order.canceled','order.shipped','order.delivered'] as $n)self::assertStringContainsString($n,$s);foreach(['wp_remote_','curl_exec(','CredentialVault'] as $n)self::assertStringNotContainsString($n,$s);}
 public function testIntakeDeduplicatesBeforeAnyOrderMutation():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyWebhookIntake.php');self::assertStringContainsString('EtsyWebhookVerifier::verify',$s);self::assertStringContainsString('->claim(',$s);self::assertStringContainsString("'order_mutation_performed'=>false",$s);}
 public function testDedupUsesAtomicInsertIgnore():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyWebhookDeduplicator.php');self::assertStringContainsString('INSERT IGNORE',$s);self::assertStringContainsString('if($inserted===false)',$s);self::assertStringContainsString('digiforge_idempotency',$s);self::assertStringContainsString('operation_key',$s);self::assertStringContainsString('operation_type',$s);self::assertStringContainsString('status',$s);self::assertStringContainsString('updated_at',$s);self::assertStringContainsString("'process_permitted'=>false",$s);}
}
