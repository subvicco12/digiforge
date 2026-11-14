<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PortalNotificationPreferenceTest extends TestCase {
 public function testOnlyRoutineSuccessCanBeSuppressedAndMalformedPreferenceFailsVisible():void {
  require_once dirname(__DIR__,2).'/includes/Portal/NotificationPreferences.php';
  self::assertFalse(DigiForge\Portal\NotificationPreferences::show(false,'0'));
  foreach(['','1',null,'unexpected',[],false] as $stored)self::assertTrue(DigiForge\Portal\NotificationPreferences::show(false,$stored));
  foreach(['0','1','',null,[]] as $stored)self::assertTrue(DigiForge\Portal\NotificationPreferences::show(true,$stored));
 }
}
