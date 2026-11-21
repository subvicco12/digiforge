<?php
declare(strict_types=1);
final class PrintifyNativeTransportTimingTest extends PHPUnit\Framework\TestCase {
 public function testNativeDependenciesAreRecheckedAcrossDispatchWithoutRetry():void {
  $lines=[];$exit=0;exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../fixtures/PrintifyNativeTransportTiming.php'),$lines,$exit);self::assertSame(0,$exit);$r=json_decode(implode("\n",$lines),true,32,JSON_THROW_ON_ERROR);
  foreach(['before_revocation','before_package'] as $case){self::assertSame('DENIED',$r[$case]['status']);self::assertSame(0,$r[$case]['calls']);self::assertSame(0,$r[$case]['calls_after_replay']);}
  foreach(['after_revocation','after_package'] as $case){self::assertSame('UNKNOWN',$r[$case]['status']);self::assertSame(1,$r[$case]['calls']);self::assertSame(1,$r[$case]['calls_after_replay']);self::assertTrue($r[$case]['recovery_identity']);self::assertFalse($r[$case]['retry_permitted']);self::assertTrue($r[$case]['replay_denied']);}
  self::assertSame('SUCCEEDED',$r['ordinary']['status']);self::assertSame(1,$r['ordinary']['calls_after_replay']);self::assertTrue($r['ordinary']['replay_denied']);
 }
}
