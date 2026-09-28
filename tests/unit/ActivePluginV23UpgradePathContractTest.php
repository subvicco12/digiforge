<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ActivePluginV23UpgradePathContractTest extends TestCase {
 public function testNormalBootRunsAdditiveV23ChainAfterHistoricalMigrator():void {
  $c=file_get_contents(__DIR__.'/../../includes/Core/Plugin.php');
  foreach(['use DigiForge\\Database\\V6OperationalSchema;','use DigiForge\\Database\\ScopedPolicySchema;','V6OperationalSchema::migrateIfNeeded()','ScopedPolicySchema::migrateIfNeeded()'] as $v)self::assertStringContainsString($v,$c);
  self::assertGreaterThan(strpos($c,'BusinessScopeInstaller::migrateIfNeeded()'),strpos($c,'V6OperationalSchema::migrateIfNeeded()'));
  self::assertGreaterThan(strpos($c,'V6OperationalSchema::migrateIfNeeded()'),strpos($c,'ScopedPolicySchema::migrateIfNeeded()'));
  self::assertLessThan(strpos($c,'\\DigiForge\\ProductFactory\\AssetStorage::ensureProtectedRoot()'),strpos($c,'ScopedPolicySchema::migrateIfNeeded()'));
 }
 public function testActivationAndBootBothReachV23():void {
  $a=file_get_contents(__DIR__.'/../../includes/Core/Activator.php');$b=file_get_contents(__DIR__.'/../../includes/Core/Plugin.php');
  foreach([$a,$b] as $c){self::assertStringContainsString('V6OperationalSchema::migrateIfNeeded()',$c);self::assertStringContainsString('ScopedPolicySchema::migrateIfNeeded()',$c);}
 }
}