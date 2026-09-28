<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ControlApiActivationGuardTest extends TestCase{
 public function testControlMutationCannotUnlockExternalExecutionBeforeActivationAuthorization():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/Controller.php');
  self::assertStringContainsString('digiforge_activation_not_authorized',$s);
  self::assertStringContainsString("\$key !== 'stop_all' && \$enabled && Settings::get('activation_authorized', false) !== true",$s);
  self::assertStringContainsString("\$key === 'stop_all' && ! \$enabled && Settings::get('activation_authorized', false) !== true",$s);
  $guard=strpos($s,'digiforge_activation_not_authorized');
  $write=strpos($s,'Settings::set($key, $enabled');
  self::assertNotFalse($guard);
  self::assertNotFalse($write);
  self::assertLessThan($write,$guard);
 }

    public function testProtectedPostureRestTransitionUsesAtomicSettingsGuard(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/Controller.php');
        self::assertStringContainsString("'/protection/restore'",$source);
        self::assertStringContainsString('Settings::protectProduction()',$source);
        self::assertStringContainsString("'external_actions_performed' => false",$source);
        self::assertStringContainsString("'permission_callback' => [\$this, 'can_manage']",$source);
        self::assertStringNotContainsString("Settings::set('activation_authorized'",$source);
    }
}
