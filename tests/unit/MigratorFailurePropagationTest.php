<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class MigratorFailurePropagationTest extends TestCase{
 public function testMigratorExposesBooleanOutcomeAndCallersFailClosed():void{
  $root=dirname(__DIR__,2).'/';
  $m=(string)file_get_contents($root.'includes/Database/Migrator.php');
  self::assertStringContainsString('public function maybe_migrate(): bool',$m);
  self::assertStringContainsString('public function migrate(): bool',$m);
  self::assertStringContainsString('return false;',$m);
  self::assertStringContainsString('return true;',$m);
  $a=(string)file_get_contents($root.'includes/Core/Activator.php');
  self::assertStringContainsString('if (! (new Migrator())->migrate())',$a);
  $p=(string)file_get_contents($root.'includes/Core/Plugin.php');
  self::assertStringContainsString('if (! (new Migrator())->maybe_migrate())',$p);
 }
}
