<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyScopedActivationTest extends TestCase
{
 public function testPrintifyAuthorizationIsScopedAndFailClosed():void
 {
  $root=dirname(__DIR__,2);
  $settings=(string)file_get_contents($root.'/includes/Core/Settings.php');
  $config=(string)file_get_contents($root.'/includes/Core/Config.php');
  self::assertStringContainsString('printify_activation_authorized',$config);
  self::assertStringContainsString("'default' => false, 'writable' => false",$config);
  self::assertStringContainsString('activatePrintify',$settings);
  self::assertStringContainsString("self::is_enabled('product_development')",$settings);
  self::assertStringContainsString("(\$switch !== 'printify' || self::get('printify_activation_authorized', false) === true)",$settings);
  self::assertStringContainsString("['research', 'ai', 'product_development', 'etsy_draft', 'printify', 'gelato', 'etsy_publish', 'order_automation', 'gst_automation']",$settings);
  self::assertStringContainsString("'printify_activation_authorized' => false",$settings);
  self::assertStringContainsString('order_automation_activation_authorized',$settings);
  self::assertStringContainsString('gelato_activation_authorized',$settings);
 }
}
