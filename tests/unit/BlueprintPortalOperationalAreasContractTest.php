<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class BlueprintPortalOperationalAreasContractTest extends TestCase {
 public function testBlueprintOperationalAreasAreFirstClassPortalViews():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['Businesses / Brands / Shops','Automation / Queues','Finance & GST','Analytics','Settings'] as $label) self::assertStringContainsString($label,$c);
  foreach(['businessOperations()','automationOperations()','analyticsOperations()','settingsOperations()'] as $call) self::assertStringContainsString($call,$c);
 }
 public function testNewOperationalViewsCannotGrantOrTriggerExternalExecution():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  self::assertStringContainsString('Viewing this page never runs, retries or replays a job.',$c);
  self::assertStringContainsString('<span>Execution authority</span><b>NO</b>',$c);
  self::assertStringContainsString('High-risk execution controls remain in System & Controls with their existing authorization gates.',$c);
 }
}