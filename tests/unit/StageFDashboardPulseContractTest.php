<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class StageFDashboardPulseContractTest extends TestCase
{
 public function testDashboardHasFailClosedActionOrientedStageFPulse():void{
  $p=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['Stage-F operator pulse','Stage-F operational readiness is not inferred.','Open approvals','Open attention','Open reconciliation','External execution authority</span><b>NO'] as $v)self::assertStringContainsString($v,$p);
 }
}
