<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ActivatorMigrationOrderTest extends TestCase{
 public function testSchemaInstallersAreNotInvokedTwice():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Activator.php');
  foreach(['ListingSchema::migrateIfNeeded()','OrderSchema::migrateIfNeeded()','FinanceSchema::migrateIfNeeded()'] as $call)self::assertSame(1,substr_count($s,$call),$call);
  self::assertSame(1,substr_count($s,'PodSchema::migrateIfNeeded()'));
  self::assertSame(1,substr_count($s,'ProductionSchema::migrateIfNeeded()'));
 }
}
