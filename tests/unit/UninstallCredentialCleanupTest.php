<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class UninstallCredentialCleanupTest extends TestCase{
 public function testOptedInUninstallRemovesManagedCredentialKeyEnvelope():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/uninstall.php');
  self::assertStringContainsString("delete_option('digiforge_credential_key_envelope');",$s);
  $gate=strpos($s,"if (! get_option('digiforge_cleanup_on_uninstall', false)) { return; }");
  $delete=strpos($s,"delete_option('digiforge_credential_key_envelope');");
  self::assertNotFalse($gate);
  self::assertNotFalse($delete);
  self::assertLessThan($delete,$gate);
 }
}
