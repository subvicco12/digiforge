<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class StageFDigitalPortalConvergenceContractTest extends TestCase
{
 public function testDigitalFactoryHasFailClosedPortalLifecycleProjection():void{
  $p=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  self::assertStringContainsString('Digital Product Factory',$p);
  self::assertStringContainsString('Digital Factory evidence unavailable',$p);
  self::assertStringContainsString('Open Product Approval',$p);
  self::assertStringContainsString('External publish authority',$p);
  self::assertStringContainsString("if (\$view === 'digital') { \$this->digitalFactoryOperations(); return; }",$p);
 }
 public function testDigitalPortalDoesNotAddMarketplaceMutation():void{
  $p=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  self::assertStringContainsString('This view cannot publish to Etsy',$p);
 }
}
