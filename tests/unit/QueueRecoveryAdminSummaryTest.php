<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use DigiForge\Queue\RecoveryAdminSummary;
use PHPUnit\Framework\TestCase;
final class QueueRecoveryAdminSummaryTest extends TestCase
{
 public function testSummarizesOnlyAuthoritativeAttentionStates():void{
  $r=RecoveryAdminSummary::summarize(['FAILED'=>2,'BLOCKED'=>1,'HUMAN_REVIEW'=>3,'DEAD_LETTER'=>4,'SUCCESS'=>99],true);
  self::assertTrue($r['query_ok']);self::assertSame(10,$r['attention_total']);self::assertSame(['FAILED'=>2,'BLOCKED'=>1,'HUMAN_REVIEW'=>3,'DEAD_LETTER'=>4],$r['states']);self::assertTrue($r['recovery_required']);self::assertFalse($r['external_actions_performed']);
 }
 public function testQueryFailureIsFailClosedAndNotPresentedAsEmptyQueue():void{
  $r=RecoveryAdminSummary::summarize(['FAILED'=>9],false);
  self::assertFalse($r['query_ok']);self::assertSame(0,$r['attention_total']);self::assertSame([],$r['states']);self::assertTrue($r['recovery_required']);self::assertFalse($r['external_actions_performed']);
 }
 public function testMalformedCountsDoNotBecomeAttention():void{
  $r=RecoveryAdminSummary::summarize(['FAILED'=>'5','BLOCKED'=>-1,'HUMAN_REVIEW'=>2],true);
  self::assertSame(2,$r['attention_total']);self::assertSame(0,$r['states']['FAILED']);self::assertSame(0,$r['states']['BLOCKED']);
 }
}
