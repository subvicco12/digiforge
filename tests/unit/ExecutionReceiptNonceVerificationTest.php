<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionReceiptNonceVerificationTest extends TestCase{
 public function testReceiptDoesNotUseUnconditionalNonceAcceptance():void{
  $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionReceipt.php');self::assertIsString($s);
  self::assertStringNotContainsString('fn(string $nonce):bool=>true',$s);
  self::assertStringContainsString('hash_equals',$s);
  self::assertStringContainsString("authorization']['nonce",$s);
 }
}
