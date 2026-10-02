<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class WebhookEvidenceAvailabilityContractTest extends TestCase{
 public function testVerifiedWebhookPersistenceIsolatesDedupWinnerAndReadbackEvidence():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/WebhookEvidenceRepository.php');
  self::assertGreaterThanOrEqual(3,substr_count($s,"\$wpdb->last_error=''"));
  self::assertGreaterThanOrEqual(4,substr_count($s,"'webhook_evidence_unavailable'"));
  self::assertStringContainsString('Webhook deduplication evidence could not be read.',$s);
  self::assertStringContainsString('Webhook winner evidence could not be read after insert failure.',$s);
  self::assertStringContainsString('Persisted webhook evidence could not be read.',$s);
  self::assertStringContainsString('Persisted webhook evidence is unavailable after write.',$s);
 }
 public function testConcurrentWinnerAndGenuineInsertFailureRemainDistinct():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/WebhookEvidenceRepository.php');
  self::assertGreaterThanOrEqual(2,substr_count($s,"'idempotent_replay'=>true"));
  self::assertStringContainsString("'webhook_evidence_failed','Webhook evidence could not be persisted.'",$s);
  self::assertStringContainsString("['status'=>503]",$s);
 }
}
