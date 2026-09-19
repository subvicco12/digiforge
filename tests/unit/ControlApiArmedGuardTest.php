<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ControlApiArmedGuardTest extends TestCase{
 public function testControlMutationCannotEnableExternalSwitchBeforeAutomationIsArmed():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/Controller.php');
  self::assertStringContainsString('digiforge_automation_not_armed',$s);
  self::assertStringContainsString("\$key !== 'stop_all' && \$enabled && Settings::get('automation_armed', false) !== true",$s);
  $guard=strpos($s,'digiforge_automation_not_armed');
  $write=strpos($s,'Settings::set($key, $enabled');
  self::assertNotFalse($guard);
  self::assertNotFalse($write);
  self::assertLessThan($write,$guard);
 }
}
