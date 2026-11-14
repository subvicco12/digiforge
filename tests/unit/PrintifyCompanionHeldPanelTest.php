<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForgePrintifyConnector\Preparation\{HeldPreparationPanel,NativePortAdapter};
require_once __DIR__.'/../fixtures/printify-connector/stage34/companion-test-bootstrap.php';
final class PrintifyCompanionHeldPanelTest extends TestCase {
 public function testMissingNativeAuthorityCannotExposeHeldSourceOrExecutionControls():void {
  self::assertTrue(class_exists(HeldPreparationPanel::class),'Protected read-only source inspection required.');[$s]=(require __DIR__.'/../fixtures/printify-connector/sprint/held-source.php')();$p=new HeldPreparationPanel(new NativePortAdapter());self::assertInstanceOf(WP_Error::class,$p->snapshot($s,1,[],8,9,str_repeat('b',64)));$html=$p->render($s,1,[],8,9,str_repeat('b',64));self::assertStringContainsString('No mutation authorized',$html);self::assertStringNotContainsString('<form',$html);self::assertStringNotContainsString('<button',$html);self::assertInstanceOf(WP_Error::class,DigiForgePrintifyConnector\Plugin::heldPreparationPanel()->snapshot($s,1,[],8,9,str_repeat('b',64)));
 }
}
