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
   ['validation_result'=>'WARNING','review_status'=>'REVIEW_REQUIRED'],
   ['validation_result'=>'NOT_APPLICABLE','review_status'=>'ACCEPTED'],
  ]);
  self::assertSame(5,$r['total']);
  self::assertSame(['FAIL'=>1,'NOT_APPLICABLE'=>1,'PASS'=>1,'PENDING'=>1,'WARNING'=>1],$r['validation_results']);
  self::assertSame(['ACCEPTED'=>1,'APPROVED'=>1,'REVIEW_REQUIRED'=>1,'UNREVIEWED'=>2],$r['review_statuses']);
  self::assertSame(0,$r['invalid']); self::assertSame(2,$r['attention']);
  self::assertFalse($r['readiness_inferred']);
  self::assertFalse($r['external_actions_performed']);
  self::assertContains('WARNING', \DigiForge\DigitalFactory\Validator::RESULTS);
  self::assertContains('ACCEPTED', \DigiForge\DigitalFactory\Validator::REVIEWS);
 }
 public function testMalformedOrUnknownStatesFailClosed():void{
  $r=QaAdminSummary::summarize([[],['validation_result'=>'PASS','review_status'=>'BOGUS'],['validation_result'=>'CORRUPT','review_status'=>'APPROVED'],'bad']);
  self::assertSame(4,$r['total']);self::assertSame([], $r['validation_results']);self::assertSame([], $r['review_statuses']);self::assertSame(4,$r['invalid']);
 }
}
