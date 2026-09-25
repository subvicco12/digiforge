<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use DigiForge\DigitalFactory\OperationalAdminSummary;
use PHPUnit\Framework\TestCase;
final class DigitalOperationalAdminSummaryTest extends TestCase {
 public function testReportsPersistedStatusesWithoutInventingVocabulary():void{
  $r=OperationalAdminSummary::summarize([['status'=>'PENDING'],['status'=>'ready'],['status'=>'CUSTOM_LOCAL_STATE']],'status');
  self::assertSame(3,$r['total']); self::assertSame(['CUSTOM_LOCAL_STATE'=>1,'PENDING'=>1,'READY'=>1],$r['states']); self::assertSame(0,$r['invalid']);
  self::assertFalse($r['readiness_inferred']); self::assertFalse($r['external_actions_performed']);
 }
 public function testMalformedRowsAndUnsupportedFieldsFailClosed():void{
  $r=OperationalAdminSummary::summarize([[],['status'=>'bad state'],42],'status');
  self::assertSame(3,$r['invalid']); self::assertSame([],$r['states']);
  $unsupported=OperationalAdminSummary::summarize([['state'=>'DRAFT']],'state');
  self::assertSame(1,$unsupported['invalid']); self::assertSame([],$unsupported['states']);
 }
 public function testPackageGenerationStatusIsSupported():void{
  $r=OperationalAdminSummary::summarize([['generation_status'=>'PENDING'],['generation_status'=>'COMPLETE']],'generation_status');
  self::assertSame(['COMPLETE'=>1,'PENDING'=>1],$r['states']);
 }
}
