<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class FactoryIdempotencyHeaderBoundaryTest extends TestCase{
 public function testFactoryCreateEndpointsDoNotAcceptBodyOrQueryIdempotencyFallback():void{
  foreach(['ProductFactoryController.php','DigitalFactoryController.php'] as $file){
   $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/'.$file);
   self::assertStringContainsString("get_header('Idempotency-Key')",$s,$file);
   self::assertStringNotContainsString("get_param('idempotency_key')",$s,$file);
  }
 }
}
