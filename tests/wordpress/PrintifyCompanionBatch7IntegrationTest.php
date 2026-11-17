<?php
declare(strict_types=1);
use DigiForgePrintifyConnector\Preparation\{NativePortAdapter,ProtectedPanel};
final class PrintifyCompanionBatch7IntegrationTest extends WP_UnitTestCase {
 public function testReadOnlyInspectionRegistersNoRouteOrSchedulerAndCannotResolveUnknown():void {
  require_once dirname(__DIR__,2).'/companion/digiforge-printify-connector/digiforge-printify-connector.php';require_once __DIR__.'/../fixtures/printify-connector/batch6/SyntheticNativeCore.php';[$s]=(require __DIR__.'/../fixtures/printify-connector/batch5/fixture.php')();$c=new SyntheticNativeCore();$c->operations->operationState='UNKNOWN';$p=new ProtectedPanel(new NativePortAdapter($c));$ctx=$c->context('unknown');$routes=rest_get_server()->get_routes();$cron=_get_cron_array();$i=$p->snapshot($s,1,$ctx)['items'][0];$r=$p->inspect($s,1,$ctx,$i['subject_hash'],$i['review_revision']);self::assertSame('UNKNOWN',$r['item']['operation_state']);self::assertFalse($r['external_execution_authorized']);self::assertSame(0,$c->writeCalls);self::assertSame($routes,rest_get_server()->get_routes());self::assertSame($cron,_get_cron_array());self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));$c->fault='revoked';self::assertWPError($p->inspect($s,1,$ctx,$i['subject_hash'],$i['review_revision']));
 }
}
