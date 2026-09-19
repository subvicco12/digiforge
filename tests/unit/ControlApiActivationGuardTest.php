<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ControlApiActivationGuardTest extends TestCase{
 public function testControlMutationCannotUnlockExternalExecutionBeforeActivationAuthorization():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/Controller.php');
  self::assertStringContainsString('digiforge_activation_not_authorized',$s);
  self::assertStringContainsString("\$key !== 'stop_all' && \$enabled && Settings::get('activation_authorized', false) !== true",$s);
  self::assertStringContainsString("\$key === 'stop_all' && ! \$enabled && Settings::get('activation_authorized', false) !== true",$s);
  self::assertLessThan(strpos($s,'Settings::set(\$key, \$enabled'),strpos($s,'digiforge_activation_not_authorized'));
 }
}
