<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class LaunchCandidateEvidenceContractTest extends TestCase {
 public function testCandidateReadsFailClosedAcrossDevelopmentEntryPoints():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Launch/ExecutionEngine.php');
  self::assertStringContainsString('private function candidate(int $id): array|\\WP_Error|null',$s);
  self::assertStringContainsString("'candidate_evidence_unavailable'",$s);
  self::assertStringContainsString('$queryResult === false',$s);
  self::assertStringContainsString('$wpdb->flush();',$s);
  self::assertSame(3,substr_count($s,'if (is_wp_error($candidate)) return $candidate;'));
 }
}
