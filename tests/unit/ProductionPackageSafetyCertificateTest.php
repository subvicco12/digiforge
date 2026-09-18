<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
/** Production package safety manifest. It certifies source boundaries without activating production. */
final class ProductionPackageSafetyCertificateTest extends TestCase{
 public function testDevelopmentAndSecretArtifactsAreNotRuntimeDependencies():void{
  $root=dirname(__DIR__,2).'/';
  foreach(['composer.json','phpunit.xml.dist','phpunit.wordpress.xml.dist','phpstan.neon.dist','phpcs.xml.dist'] as $file)self::assertFileExists($root.$file,$file);
  self::assertFileDoesNotExist($root.'.env');
  self::assertFileDoesNotExist($root.'.env.production');
 }
 public function testPluginBootstrapAndDatabaseMigrationSurfaceExist():void{
  $root=dirname(__DIR__,2).'/';
  self::assertFileExists($root.'digiforge.php');
  self::assertFileExists($root.'includes/Core/Activator.php');
  self::assertFileExists($root.'includes/Database/PodSchema.php');
  self::assertFileExists($root.'includes/Database/Tables.php');
 }
 public function testControlledExecutionStillHasNoEmbeddedCredentials():void{
  $root=dirname(__DIR__,2).'/includes/POD/';
  foreach(['ControlledExecutionGate.php','ControlledExecutionTransaction.php','ExecutionOrchestrator.php'] as $file){
   $s=(string)file_get_contents($root.$file);
   self::assertDoesNotMatchRegularExpression('/(?:api[_-]?key|client[_-]?secret|access[_-]?token)\s*[=:]\s*["\'][^"\']+/i',$s,$file);
  }
 }
}
