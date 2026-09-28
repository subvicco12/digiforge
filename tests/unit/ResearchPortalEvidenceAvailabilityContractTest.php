<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ResearchPortalEvidenceAvailabilityContractTest extends TestCase {
 public function testResearchCandidateAndEvidenceReadsExposeAvailability():void {
  $portal=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach([
   'candidates(string $status, int $limit, ?string &$queryState=null)',
   'evidence(int $candidateId, ?string &$queryState=null)',
   'Research candidate evidence unavailable',
   'Candidate evidence unavailable for #',
   'No empty evidence set is inferred'
  ] as $needle) self::assertStringContainsString($needle,$portal);
 }
 public function testFailedReadsCheckDatabaseErrorBeforeEmptyState():void {
  $portal=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  self::assertStringContainsString('!is_array($rows)||!empty($wpdb->last_error)',$portal);
  self::assertStringContainsString("candidateState!=='AVAILABLE'",str_replace('$candidateState','candidateState',$portal));
 }
}
