<?php
/** Absence of independent trust cannot be replaced with a host-configured owner path. */
final class PortalSignedAuthorityOnlyTest extends PHPUnit\Framework\TestCase {
 public function testNativeOwnerFallbackCannotReplaceIndependentSignature():void {
  $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/fixtures/portal-native-owner-bypass.php');
  exec($command,$output,$status);self::assertSame(0,$status,implode("\n",$output));
 }
}
