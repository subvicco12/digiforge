<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyConnectorCompanionTest extends TestCase {
 public function testPluginSupportsMissingAndPresentHostWithoutNetworkOrDuplicateHooks():void {
  self::assertFileExists(__DIR__.'/../../companion/digiforge-printify-connector/digiforge-printify-connector.php');
  foreach(['missing','loaded'] as $mode){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/../fixtures/printify-connector/companion-probe.php',$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($process);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$err.$out);$data=json_decode($out,true);self::assertSame(1,$data['hooks']);self::assertSame(0,$data['http_calls']);self::assertSame($mode==='missing'?'MISSING_HOST_INTERFACE':'INTERFACE_AVAILABLE_AUTHORITY_UNVERIFIED',$data['status']);}
 }
}
