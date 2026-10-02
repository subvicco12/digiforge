<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingInsertEvidenceAvailabilityContractTest extends TestCase{
 public function testInsertIsolatesAllDurableEvidenceReads():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
  self::assertGreaterThanOrEqual(3,substr_count($s,"\$wpdb->last_error=''"));
  self::assertStringContainsString("'listing_idempotency_evidence_unavailable'",$s);
  self::assertStringContainsString('Listing idempotency evidence could not be read.',$s);
  self::assertStringContainsString('Listing idempotency winner evidence could not be read.',$s);
  self::assertStringContainsString("'listing_persistence_evidence_unavailable'",$s);
  self::assertStringContainsString('Persisted listing evidence could not be read.',$s);
  self::assertStringContainsString('Persisted listing evidence is unavailable after write.',$s);
 }
 public function testExistingReplayAndConflictSemanticsRemainExplicit():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
  self::assertStringContainsString("'idempotent_replay'=>true",$s);
  self::assertStringContainsString("'idempotency_payload_conflict'",$s);
  self::assertStringContainsString("'database_error','Unable to persist listing record.',500",$s);
 }
}
