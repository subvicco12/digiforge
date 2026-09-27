<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyPostCreateSequenceGuardContractTest extends TestCase {
 public function testMediaRequiresConfirmedCreateAndExactScopeIdentity():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyPostCreateSequenceGuard.php');foreach(['CREATE_DRAFT','CONFIRMED_SUCCESS','external_reference','intent_id','draft_package_id','shop_reference','resource_reference','ATTACH_IMAGE','UPLOAD_FILE','hash_equals','publish_permitted'] as $n)self::assertStringContainsString($n,$s);}
 public function testGuardDoesNotPerformTransport():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyPostCreateSequenceGuard.php');foreach(['wp_remote_','access_token','refresh_token','CredentialVault'] as $n)self::assertStringNotContainsString($n,$s);}
}
