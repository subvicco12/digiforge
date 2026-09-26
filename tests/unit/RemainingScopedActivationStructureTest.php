<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class RemainingScopedActivationStructureTest extends TestCase {
 public function testRemainingActivationGatesAreScopedAndFailClosed(): void {
  $config=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Config.php');
  $settings=(string)file_get_contents(dirname(__DIR__,2).'/includes/Core/Settings.php');
  $rest=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/Controller.php');
  foreach(['etsy_publish_activation_authorized','order_automation_activation_authorized','gst_automation_activation_authorized'] as $gate) self::assertStringContainsString($gate,$config);
  foreach(['activateEtsyPublish','activateOrderAutomation','activateGstAutomation'] as $method) self::assertStringContainsString($method,$settings);
  foreach(["'/activations/etsy-publish'","'/activations/order-automation'","'/activations/gst-automation'"] as $route) self::assertStringContainsString($route,$rest);
  self::assertStringContainsString("'external_actions_performed' => false",$rest);
 }
}
