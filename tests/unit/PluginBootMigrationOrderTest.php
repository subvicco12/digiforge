<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PluginBootMigrationOrderTest extends TestCase{
 public function testBootSchemaInstallersAreNotInvokedTwice():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Plugin.php');
  foreach(['ListingSchema::migrateIfNeeded()','OrderSchema::migrateIfNeeded()','FinanceSchema::migrateIfNeeded()','PodSchema::migrateIfNeeded()','ProductionSchema::migrateIfNeeded()'] as $call)self::assertSame(1,substr_count($s,$call),$call);
  self::assertSame(1,substr_count($s,'(new Migrator())->maybe_migrate()'));
  self::assertSame(1,substr_count($s,'BusinessScopeInstaller::migrateIfNeeded()'));
 }
}
