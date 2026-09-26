<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class FinalScopedActivationFoundationTest extends TestCase {
 public function testRemainingCapabilitiesRequireDedicatedAuthorization():void {
  $config=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Config.php');
  $settings=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Settings.php');
  $rest=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/Controller.php');
  foreach(['gelato_activation_authorized','etsy_publish_activation_authorized','order_automation_activation_authorized','gst_automation_activation_authorized'] as $key){self::assertStringContainsString($key,$config);self::assertStringContainsString($key,$settings);}
  foreach(['activateGelato','activateEtsyPublish','activateOrderAutomation','activateGstAutomation'] as $method){self::assertStringContainsString($method,$settings);self::assertStringContainsString($method,$rest);}
  foreach(['/activations/gelato','/activations/etsy-publish','/activations/order-automation','/activations/gst-automation'] as $route){self::assertStringContainsString($route,$rest);}
  self::assertStringContainsString("'external_actions_performed' => false",$rest);
 }
}
