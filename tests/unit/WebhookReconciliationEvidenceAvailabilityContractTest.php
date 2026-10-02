<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class WebhookReconciliationEvidenceAvailabilityContractTest extends TestCase{
 public function testSnapshotDistinguishesUnavailableHistoryFromEmptyHistory():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/WebhookReconciliationReadModel.php');
  self::assertGreaterThanOrEqual(2,substr_count($s,"\$wpdb->last_error=''"));
  self::assertStringContainsString("'evidence_state'=>'UNAVAILABLE'",$s);
  self::assertStringContainsString("'evidence_state'=>'AVAILABLE'",$s);
  self::assertStringContainsString("'DEDUP_EVIDENCE_UNAVAILABLE'",$s);
  self::assertStringContainsString("'DEDUP_EVIDENCE_MISSING'",$s);
 }
 public function testPortalDoesNotPresentUnavailableReconciliationAsZero():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Portal/Portal.php');
  self::assertStringContainsString("\$reconUnavailable",$s);
  self::assertStringContainsString('Webhook reconciliation evidence is unavailable because the current database read failed. Zero counts are not asserted.',$s);
  self::assertStringContainsString("'UNAVAILABLE':'READ ONLY'",$s);
  self::assertStringContainsString("'—':esc_html",$s);
 }
}
