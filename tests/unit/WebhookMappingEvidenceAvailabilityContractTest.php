<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class WebhookMappingEvidenceAvailabilityContractTest extends TestCase {
 public function testResolverDistinguishesUnavailableEvidence():void {
  $c=file_get_contents(__DIR__.'/../../includes/Orders/ApprovedPodMappingResolver.php');
  self::assertStringContainsString('pod_mapping_evidence_unavailable',$c);
  self::assertStringContainsString('product_type_evidence_unavailable',$c);
  self::assertStringNotContainsString('(int)$wpdb->get_var',$c);
  self::assertGreaterThanOrEqual(2,substr_count($c,"'external_execution_authorized'=>false"));
 }
 public function testWebhookStopsLineNormalizationOnResolverErrors():void {
  $c=file_get_contents(__DIR__.'/../../includes/Listings/EtsyOrderWebhookLifecycle.php');
  self::assertStringContainsString('$isDigital instanceof WP_Error',$c);
  self::assertStringContainsString('$providerMappingId instanceof WP_Error',$c);
  self::assertGreaterThanOrEqual(2,substr_count($c,'$reviewRequired=true;continue;'));
 }
}
