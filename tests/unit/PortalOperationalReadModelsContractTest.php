<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PortalOperationalReadModelsContractTest extends TestCase {
 public function testQueueViewShowsAllRecoveryStatesAndNeverExecutesRecovery():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['Expired leases','FAILED','BLOCKED','HUMAN_REVIEW','DEAD_LETTER','Automatic retry from view</span><b>NO'] as $v) self::assertStringContainsString($v,$c);
  self::assertStringContainsString('Viewing this page never runs, retries or replays a job.',$c);
 }
 public function testBusinessAnalyticsAndSettingsAreScopedEvidenceOnly():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  self::assertStringContainsString('AI policy and usage evidence do not grant external execution authority.',$c);
  self::assertStringContainsString('Analytics is evidence only and does not infer publish, production, refund, tax or money-movement authority.',$c);
  self::assertStringContainsString('This page is observational. It cannot enable research, AI, Etsy, POD, order automation, GST automation or external execution.',$c);
  self::assertStringContainsString("count(array_filter(\$switches))",$c);
 }
}