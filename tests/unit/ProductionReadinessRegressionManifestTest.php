<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
/** Cross-subsystem production-readiness regression manifest. No operational controls are changed. */
final class ProductionReadinessRegressionManifestTest extends TestCase{
 public function testCoreRegressionAndIntegrationSuitesRemainAvailable():void{
  $root=dirname(__DIR__,2).'/tests/';
  foreach([
   'test-foundation.php','test-product-factory.php','test-digital-product-factory.php',
   'unit/PodFinalMainlineCertificationTest.php',
   'wordpress/MigrationTest.php','wordpress/ExecutionReceiptRepositoryTest.php',
   'wordpress/ExecutionFailureRepositoryTest.php','wordpress/ExecutionOutcomeRepositoryTest.php'
  ] as $file)self::assertFileExists($root.$file,$file);
 }
 public function testComposerExposesRequiredQualityGates():void{
  $composer=json_decode((string)file_get_contents(dirname(__DIR__,2).'/composer.json'),true);
  self::assertIsArray($composer);
  foreach(['test','test:legacy','test:unit','analyse','standards','test:wordpress'] as $script)self::assertArrayHasKey($script,$composer['scripts']??[]);
 }
}
