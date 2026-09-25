<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use DigiForge\DigitalFactory\QaAdminSummary;
use PHPUnit\Framework\TestCase;
final class DigitalQaAdminSummaryTest extends TestCase {
 public function testSummarizesOnlyPersistedValidCheckStates():void{
  $r=QaAdminSummary::summarize([
   ['validation_result'=>'PASS','review_status'=>'APPROVED'],
   ['validation_result'=>'fail','review_status'=>'unreviewed'],
   ['validation_result'=>'PENDING','review_status'=>'UNREVIEWED'],
  ]);
  self::assertSame(3,$r['total']);
  self::assertSame(['FAIL'=>1,'PASS'=>1,'PENDING'=>1],$r['validation_results']);
  self::assertSame(['APPROVED'=>1,'UNREVIEWED'=>2],$r['review_statuses']);
  self::assertSame(0,$r['invalid']);
  self::assertFalse($r['readiness_inferred']);
  self::assertFalse($r['external_actions_performed']);
 }
 public function testMalformedOrUnknownStatesFailClosed():void{
  $r=QaAdminSummary::summarize([[],['validation_result'=>'PASS','review_status'=>'BOGUS'],['validation_result'=>'CORRUPT','review_status'=>'APPROVED'],'bad']);
  self::assertSame(4,$r['total']);self::assertSame([], $r['validation_results']);self::assertSame([], $r['review_statuses']);self::assertSame(4,$r['invalid']);
 }
}
