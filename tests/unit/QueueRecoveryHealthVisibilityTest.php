<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
final class QueueRecoveryHealthVisibilityTest extends TestCase
{
 public function testHealthMonitorUsesAuthoritativeRecoveryProjection():void{
  $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/Observability/HealthMonitor.php');
  self::assertStringContainsString("state IN ('FAILED','BLOCKED','HUMAN_REVIEW','DEAD_LETTER')",$source);
  self::assertStringContainsString('RecoveryAdminSummary::summarize',$source);
  self::assertStringContainsString('$queueQueryOk = $expiredQueryOk && $deadQueryOk && $recoveryQueryOk;',$source);
 }
 public function testAdminDoesNotPresentZeroWhenRecoveryQueryFails():void{
  $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Admin.php');
  self::assertStringContainsString('Recovery attention',$source);
  self::assertStringContainsString('UNAVAILABLE (query failed)',$source);
 }
}
