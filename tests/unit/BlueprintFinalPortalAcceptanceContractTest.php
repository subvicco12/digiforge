<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class BlueprintFinalPortalAcceptanceContractTest extends TestCase {
 public function testRequiredOperatorAreasRemainInLiveSitePortal():void {
  $p=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['Dashboard','Businesses / Brands / Shops','Approval Inbox','Attention & Recovery','Research','Product Factory','Digital Products','Production','POD — Personalized','Listings & Etsy','Orders / Personalization','Fulfillment / Providers','AI & Budget','Finance & GST','Analytics','Automation / Queues','Integrations','Audit / Reconciliation','System & Controls','Settings'] as $area) self::assertStringContainsString($area,$p);
 }
 public function testMobileAndTabletOperatorControlsRemainTouchSafe():void {
  $css=(string)file_get_contents(__DIR__.'/../../assets/portal-ui.css');
  foreach(['@media (min-width:768px) and (max-width:1199px)','@media (max-width:767px)','min-height:44px','overflow-x:auto','prefers-reduced-motion'] as $needle) self::assertStringContainsString($needle,$css);
 }
 public function testQueueRecoveryProviderAndFinanceViewsRemainEvidenceOnly():void {
  $p=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['Automatic retry from view</span><b>NO','automatic recovery, replay and retry remain NO','A prepared or approved plan is not production authorization','Finance & GST','This page is observational. It cannot enable research, AI, Etsy, POD, order automation, GST automation or external execution.'] as $needle) self::assertStringContainsString($needle,$p);
 }
 public function testRecoveryAndPodAcceptanceNeverCollapseIntoExternalAuthority():void {
  $p=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['Acceptance records a human recovery-drill decision only; it cannot execute or retry recovery.','grants no restore, retry, Etsy, POD, order, tax or other commerce authority.'] as $needle) self::assertStringContainsString($needle,$p);
 }
}
