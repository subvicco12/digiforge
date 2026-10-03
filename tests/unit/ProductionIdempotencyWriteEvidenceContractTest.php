<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductionIdempotencyWriteEvidenceContractTest extends TestCase {
 public function testIdempotencyReadInsertRaceAndTransitionWritesAreEvidenceAware():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Production/Repository.php');
  foreach(['idempotency_evidence_unavailable',"'idempotent_replay'=>true","\$inserted!==1||!empty(\$wpdb->last_error)","\$updated===false||!empty(\$wpdb->last_error)"] as $n)self::assertStringContainsString($n,$s);
  self::assertGreaterThanOrEqual(3,substr_count($s,"\$wpdb->last_error=''"));
  self::assertStringContainsString('$winner=$wpdb->get_row',$s);
 }
}