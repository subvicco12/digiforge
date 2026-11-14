<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyNativeFixtureSaltTest extends TestCase {
 public function testParallelNamespacesSeparateNonceLocksButPreserveParentChildAndAuthSalts():void {
  $file=dirname(__DIR__).'/contracts/NativeAcceptanceFixtureSalt.php';self::assertFileExists($file);require_once $file;
  $base='synthetic-only-fixed-salt';$one=NativeAcceptanceFixtureSalt::derive($base,'nonce','df012345678_');$two=NativeAcceptanceFixtureSalt::derive($base,'nonce','dfabcdef012_');self::assertNotSame($one,$two);self::assertSame($one,NativeAcceptanceFixtureSalt::derive($base,'nonce','df012345678_'));self::assertSame($base,NativeAcceptanceFixtureSalt::derive($base,'auth','df012345678_'));self::assertSame($base,NativeAcceptanceFixtureSalt::derive($base,'logged_in','dfabcdef012_'));$this->expectException(InvalidArgumentException::class);NativeAcceptanceFixtureSalt::derive($base,'nonce','unscoped');
 }
}
