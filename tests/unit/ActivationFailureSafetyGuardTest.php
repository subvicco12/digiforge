<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
/** Fail-closed activation regression guard: defaults/rewrite finalization must stay after every schema gate. */
final class ActivationFailureSafetyGuardTest extends TestCase{
 public function testActivationFinalizationOccursOnlyAfterAllMigrationGates():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Activator.php');
  $final=strpos($s,'Settings::ensure_defaults()');
  self::assertNotFalse($final);
  foreach([
   'ListingSchema::migrateIfNeeded()','OrderSchema::migrateIfNeeded()','FinanceSchema::migrateIfNeeded()',
   'PodSchema::migrateIfNeeded()','ProductionSchema::migrateIfNeeded()',
   '(new Migrator())->migrate()','BusinessScopeInstaller::migrateIfNeeded()'
  ] as $gate){
   $pos=strpos($s,$gate);self::assertNotFalse($pos,$gate);self::assertLessThan($final,$pos,$gate);
  }
  self::assertGreaterThan($final,strpos($s,'flush_rewrite_rules()'));
 }
}
