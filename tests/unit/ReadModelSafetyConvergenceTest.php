<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use DigiForge\DigitalFactory\OperationalAdminSummary;
use DigiForge\DigitalFactory\QaAdminSummary;
use DigiForge\Queue\RecoveryAdminSummary;
use PHPUnit\Framework\TestCase;
final class ReadModelSafetyConvergenceTest extends TestCase
{
 public function testDigitalQaSummaryIsReadOnlyWithoutReadinessInference():void{
  foreach([[],[['validation_result'=>'PENDING','review_status'=>'UNREVIEWED']],[null]] as $rows){
   $summary=QaAdminSummary::summarize($rows);
   self::assertFalse($summary['readiness_inferred']);
   self::assertFalse($summary['external_actions_performed']);
  }
 }
 public function testOperationalSummaryAllReturnPathsAreReadOnlyWithoutReadinessInference():void{
  foreach([
   OperationalAdminSummary::summarize([], 'status'),
   OperationalAdminSummary::summarize([['status'=>'PERSISTED']], 'status'),
   OperationalAdminSummary::summarize([['generation_status'=>'PERSISTED']], 'generation_status'),
   OperationalAdminSummary::summarize([['status'=>'PERSISTED']], 'unsupported_field'),
  ] as $summary){
   self::assertFalse($summary['readiness_inferred']);
   self::assertFalse($summary['external_actions_performed']);
  }
 }
 public function testRecoverySummarySuccessAndFailurePathsRemainReadOnlyAndFailClosed():void{
  $failed=RecoveryAdminSummary::summarize([],false);
  self::assertFalse($failed['query_ok']);
  self::assertTrue($failed['recovery_required']);
  self::assertFalse($failed['external_actions_performed']);
  $healthy=RecoveryAdminSummary::summarize([],true);
  self::assertTrue($healthy['query_ok']);
  self::assertFalse($healthy['recovery_required']);
  self::assertFalse($healthy['external_actions_performed']);
  $attention=RecoveryAdminSummary::summarize(['FAILED'=>'2'],true);
  self::assertSame(2,$attention['attention_total']);
  self::assertTrue($attention['recovery_required']);
  self::assertFalse($attention['external_actions_performed']);
 }
}
