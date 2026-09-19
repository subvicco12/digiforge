<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PackageIntegrityStructureTest extends TestCase{
 public function testPackageScriptSelfVerifiesGeneratedChecksum():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/bin/package-plugin.sh');
  self::assertStringContainsString('sha256sum digiforge.zip > digiforge.zip.sha256',$s);
  self::assertStringContainsString('sha256sum --check digiforge.zip.sha256',$s);
  self::assertLessThan(strpos($s,'sha256sum --check digiforge.zip.sha256'),strpos($s,'sha256sum digiforge.zip > digiforge.zip.sha256'));
 }
 public function testDevelopmentSurfacesRemainExportIgnored():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/.gitattributes');
  foreach(['/.github export-ignore','/bin export-ignore','/docs export-ignore','/tests export-ignore','/composer.json export-ignore','/composer.lock export-ignore','/phpunit.xml.dist export-ignore'] as $rule){
   self::assertStringContainsString($rule,$s,$rule);
  }
 }
}
