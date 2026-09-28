<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class OperatorNavigationResponsiveContractTest extends TestCase {
 public function testApprovalLinksNavigateOnlyToGovernedInternalViews():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/U3ApprovalInbox.php');
  self::assertStringContainsString('Open governed workflow',$c);
  foreach(["'listings'","'pod_personalized'","'orders'"] as $v) self::assertStringContainsString($v,$c);
  self::assertStringNotContainsString('wp_remote_',$c);
  self::assertStringNotContainsString('Settings::set',$c);
 }
 public function testOperatorTablesStayUsableOnTabletAndMobile():void {
  $c=file_get_contents(__DIR__.'/../../assets/portal-ui.css');
  self::assertStringContainsString('@media (max-width:1199px)',$c);
  self::assertStringContainsString('overflow-x:auto!important',$c);
  self::assertStringContainsString('.df-button-compact{min-height:44px!important',$c);
 }
}