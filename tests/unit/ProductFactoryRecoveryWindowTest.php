<?php
declare(strict_types=1);
use DigiForge\ProductFactory\ApprovalAutomation;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/ProductFactory/ApprovalAutomation.php';
final class ProductFactoryRecoveryWindowTest extends TestCase {
 public function testExistingRecoveryWindowHasExactBoundaryWithoutExtendingExpiredRuns():void {
  $method=new ReflectionMethod(ApprovalAutomation::class,'recoveryWindowExpired');$worker=new ApprovalAutomation();
  self::assertFalse($method->invoke($worker,1000,1000+21600));self::assertTrue($method->invoke($worker,1000,1000+21601));
 }
}
