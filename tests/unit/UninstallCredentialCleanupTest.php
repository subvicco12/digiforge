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
 public function testOptedInUninstallCoversEveryRegisteredDigiForgeTable():void{
  $tables=(string)file_get_contents(dirname(__DIR__,2).'/includes/Database/Tables.php');
  $uninstall=(string)file_get_contents(dirname(__DIR__,2).'/uninstall.php');
  preg_match_all("/self::name\\('([^']+)'\\)/",$tables,$matches);
  $registered=array_values(array_unique($matches[1]??[]));
  self::assertNotEmpty($registered);
  foreach($registered as $suffix){
   self::assertStringContainsString("'".$suffix."'",$uninstall,'Uninstall cleanup is missing registered table suffix: '.$suffix);
  }
 }
}
