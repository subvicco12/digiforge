<?php
declare(strict_types=1);
use DigiForgePrintifyConnector\Preparation\{NativePortAdapter,RecoveryDiagnostics};
final class PrintifyCompanionBatch8IntegrationTest extends WP_UnitTestCase {
 public function testRecoveryObservationLeavesStopAndSchedulerUnchanged():void {require_once dirname(__DIR__,2).'/companion/digiforge-printify-connector/digiforge-printify-connector.php';require_once __DIR__.'/../fixtures/printify-connector/batch6/SyntheticNativeCore.php';[$s]=(require __DIR__.'/../fixtures/printify-connector/batch5/fixture.php')();$c=new SyntheticNativeCore();$c->emergency();$d=new RecoveryDiagnostics(new NativePortAdapter($c));$cron=_get_cron_array();$r=$d->snapshot($s,1);self::assertSame('UNRESOLVED_UNKNOWN',$r['state']);self::assertTrue($r['emergency_acknowledged']);self::assertFalse($r['external_execution_authorized']);self::assertSame(0,$c->writeCalls);self::assertSame($cron,_get_cron_array());self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));$c->fault='revoked';self::assertWPError($d->snapshot($s,1));}
}
