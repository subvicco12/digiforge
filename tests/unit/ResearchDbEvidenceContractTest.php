<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ResearchDbEvidenceContractTest extends TestCase{
 public function testResearchReadsDedupeAndIdempotencyFailClosed():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Research/Repository.php');
  foreach(['array|\\WP_Error|null','research_evidence_unavailable','research_idempotency_evidence_unavailable',"\$wpdb->last_error=''"] as $n)self::assertStringContainsString($n,$s);
  self::assertStringContainsString("\$inserted!==1||!empty(\$wpdb->last_error)",$s);
 }
}