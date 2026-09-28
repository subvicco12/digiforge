<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class V23ReleaseReadinessPortalCompletenessContractTest extends TestCase {
 public function testBlueprintPortalAreasRemainFirstClassAndGoverned():void {
  $c=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['Dashboard','Businesses / Brands / Shops','Approval Inbox','Attention & Recovery','Research','Product Factory','Digital Products','Production','POD — Personalized','Listings & Etsy','Orders / Personalization','Fulfillment / Providers','AI & Budget','Finance & GST','Analytics','Automation / Queues','Integrations','Audit / Reconciliation','System & Controls','Settings'] as $v)self::assertStringContainsString($v,$c);
  foreach(['UNKNOWN provider outcomes require reconciliation before any retry','cannot override STOP ALL','Viewing this page never runs, retries or replays a job.','Audit context payloads are excluded'] as $v)self::assertStringContainsString($v,$c);
 }
 public function testReadinessStillRequiresRecoveryPassAndLockedPosture():void {
  $c=file_get_contents(__DIR__.'/../../includes/Operations/Readiness.php');
  foreach(["'schema_current'","'stop_all_active'","'activation_not_authorized'","'automation_unarmed'","'no_effective_feature_switches'","'recovery_drill_passed'","'READY_LOCKED'","'REVIEW_REQUIRED'"] as $v)self::assertStringContainsString($v,$c);
 }
 public function testV23ReleaseMetadataAndMigrationCoverageAreConsistent():void {
  $p=file_get_contents(__DIR__.'/../../digiforge.php');self::assertStringContainsString("const DIGIFORGE_DB_VERSION = '23';",$p);
  $m=file_get_contents(__DIR__.'/../wordpress/MigrationTest.php');foreach(['testSchemaTwentyTwoUpgradeCreatesV23ScopedPolicyAndReactivationIsIdempotent','testV23MarkerWithMissingTableRepairsBeforeReportingSuccess','testV23ReactivationPreservesScopedPolicyTableAndRows'] as $v)self::assertStringContainsString($v,$m);
 }
}