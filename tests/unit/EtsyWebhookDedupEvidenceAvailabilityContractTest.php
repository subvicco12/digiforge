<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyWebhookDedupEvidenceAvailabilityContractTest extends TestCase{
 public function testIgnoredClaimRequiresReadableExistingEvidenceBeforeDuplicateDecision():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyWebhookDeduplicator.php');
  self::assertStringContainsString("\$wpdb->last_error='';\$row=\$wpdb->get_row",$s);
  self::assertStringContainsString("'digiforge_etsy_webhook_idempotency_evidence_unavailable'",$s);
  self::assertStringContainsString('Webhook deduplication evidence could not be read.',$s);
  self::assertStringContainsString('Webhook deduplication evidence is unavailable after an ignored claim.',$s);
  self::assertStringContainsString('ETSY_WEBHOOK_RETRY_CLAIMED',$s);
  self::assertStringContainsString('ETSY_WEBHOOK_DUPLICATE',$s);
 }
}
