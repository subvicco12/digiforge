<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class WebhookCompletionEvidenceContractTest extends TestCase {
 public function testIntakeSurfacesIncompleteDurableCompletionEvidence():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyWebhookIntake.php');
  foreach(['digiforge_etsy_webhook_completion_evidence_unavailable','digiforge_etsy_webhook_failure_evidence_unavailable','reconciliation_required','external_execution_performed'] as $n)self::assertStringContainsString($n,$s);
  self::assertStringContainsString('if(!$dedupProcessed||!$evidenceProcessed)',$s);
  self::assertStringContainsString('if(!$dedupFailed||!$evidenceFailed)',$s);
 }
 public function testCompletionRepositoriesClearAndCheckDbErrors():void{
  foreach(['EtsyWebhookDeduplicator.php','WebhookEvidenceRepository.php'] as $file){$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/'.$file);self::assertStringContainsString("\$wpdb->last_error=''",$s);self::assertStringContainsString('empty($wpdb->last_error)',$s);}
 }
}