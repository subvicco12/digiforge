<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyOperationApprovedScopeEvidenceContractTest extends TestCase{
 public function testApprovedScopeSeparatesUnavailableReadsFromInvalidScope():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationRepository.php');
  self::assertGreaterThanOrEqual(3,substr_count($s,"\$wpdb->last_error=''"));
  foreach(['Approved intent scope evidence could not be read.','Approved draft-package scope evidence could not be read.','Approved listing scope evidence could not be read.'] as $m)self::assertStringContainsString($m,$s);
  self::assertGreaterThanOrEqual(3,substr_count($s,"'evidence_unavailable'"));
  foreach(['intent_not_approved','package_not_approved','scope_mismatch','shop_scope_mismatch'] as $c)self::assertStringContainsString("'".$c."'",$s);
 }
}
