<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\Connector\Printify\{NativeHeldJournalState as State,NativePortContract};
final class NativeHeldJournalStateTest extends TestCase {
 public function testClosedCandidateBindingAndOneShotLocalFence():void {
  $b=NativePrintifyContractFixture::binding();$r=State::prepare($b,str_repeat('f',64),1);
  self::assertSame('HELD',$r['state']);self::assertFalse($r['approval_verified']);self::assertFalse($r['dispatch_permitted']);
  $f=State::advance($r,'FENCE',1,2,str_repeat('a',64));self::assertSame('FENCED',$f['state']);self::assertSame(2,$f['revision']);self::assertSame($b,$f['binding']);
  $this->expectException(InvalidArgumentException::class);State::advance($f,'FENCE',2,3,str_repeat('b',64));
 }
 public function testUnknownIsNeverRetriedCancelledOrRearmed():void {
  $r=State::advance(State::prepare(NativePrintifyContractFixture::binding(),str_repeat('f',64),1),'FENCE',1,2,str_repeat('a',64));$u=State::advance($r,'UNKNOWN',2,3);
  foreach(['FENCE','CANCEL','RESET','SUCCESS','FAILURE','UNKNOWN']as $action){try{State::advance($u,$action,3,999999);self::fail('Terminal state reopened');}catch(InvalidArgumentException){self::assertTrue(true);}}
  self::assertSame($r['fence_hash'],$u['fence_hash']);self::assertFalse($u['independent_checkpoint_verified']);
 }
 public function testCancellationAndStaleRevision():void {
  $r=State::prepare(NativePrintifyContractFixture::binding(),str_repeat('f',64),1);$c=State::advance($r,'CANCEL',1,2);self::assertSame('CANCELLED',$c['state']);self::assertNull($c['fence_hash']);
  $this->expectException(InvalidArgumentException::class);State::advance($r,'FENCE',2,2,str_repeat('a',64));
 }
 public function testWrongSchemaCannotBeAcceptedAsCurrentState():void {
  $r=State::prepare(NativePrintifyContractFixture::binding(),str_repeat('f',64),1);$r['schema']='WRONG';self::assertFalse(State::valid($r));$this->expectException(InvalidArgumentException::class);State::advance($r,'FENCE',1,2,str_repeat('a',64));
 }
 public function testChangedOrExtraEvidenceCannotBecomeAuthority():void {
  $r=State::prepare(NativePrintifyContractFixture::binding(),str_repeat('f',64),1);$r['dispatch_permitted']=true;
  $this->expectException(InvalidArgumentException::class);State::advance($r,'FENCE',1,2,str_repeat('a',64));
 }
}
