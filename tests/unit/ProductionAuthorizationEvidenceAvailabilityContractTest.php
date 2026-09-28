<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductionAuthorizationEvidenceAvailabilityContractTest extends TestCase {
 public function testCreateBlocksWhenReadEvidenceIsUnavailable():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ProductionAuthorizationRepository.php');
  self::assertGreaterThanOrEqual(2,substr_count($c,'authorization_evidence_unavailable'));
  self::assertStringContainsString('Production authorization evidence is unavailable; package creation is blocked.',$c);
  self::assertGreaterThanOrEqual(2,substr_count($c,"'external_execution_authorized'=>false"));
 }
 public function testPostWriteConfirmationFailureForbidsAutomaticRetry():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ProductionAuthorizationRepository.php');
  self::assertStringContainsString('authorization_package_confirmation_unavailable',$c);
  self::assertStringContainsString('authorization_review_confirmation_unavailable',$c);
  self::assertGreaterThanOrEqual(2,substr_count($c,"'retry_permitted'=>false"));
  self::assertStringContainsString('do not retry automatically.',$c);
 }
}
