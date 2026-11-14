<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyFixtureIsolationTest extends TestCase {
 public function testSuiteConfigurationIsolationExists():void{self::assertFileExists(__DIR__.'/../wordpress/FixtureConfiguration.php');}
 public function testReusedDatabaseGetsDistinctNamespaceWithoutDeletingExistingTables():void {
  require_once __DIR__.'/../wordpress/FixtureConfiguration.php';$source=tempnam(sys_get_temp_dir(),'df-original-config-');file_put_contents($source,"<?php \$table_prefix='wptests_';\n");$original=file_get_contents($source);
  try{$a=DigiForgeTestFixtureConfiguration::prepare($source);$b=DigiForgeTestFixtureConfiguration::prepare($source);self::assertNotSame($a['prefix'],$b['prefix']);foreach([$a,$b]as $c){self::assertMatchesRegularExpression('/^df[a-f0-9]{9}_$/D',$c['prefix']);self::assertSame(0600,fileperms($c['path'])&0777);$table_prefix='';require $c['path'];self::assertSame($c['prefix'],$table_prefix);self::assertStringNotContainsString('DROP',file_get_contents($c['path']));self::assertStringNotContainsString('TRUNCATE',file_get_contents($c['path']));}self::assertSame($original,file_get_contents($source));}finally{@unlink($source);}
 }
 public function testMissingOriginalConfigurationDenied():void{require_once __DIR__.'/../wordpress/FixtureConfiguration.php';$this->expectException(RuntimeException::class);DigiForgeTestFixtureConfiguration::prepare('/missing/synthetic-config.php');}
 public function testChildWorkerReusesParentNamespaceWithoutReinstalling():void{require_once __DIR__.'/../wordpress/FixtureConfiguration.php';$source=tempnam(sys_get_temp_dir(),'df-config-');file_put_contents($source,"<?php \$table_prefix='wptests_';");try{$a=DigiForgeTestFixtureConfiguration::prepare($source);putenv('WP_TESTS_SKIP_INSTALL=1');$b=DigiForgeTestFixtureConfiguration::resolve($source);self::assertSame($a,$b);}finally{putenv('WP_TESTS_SKIP_INSTALL');@unlink($source);}}

}
