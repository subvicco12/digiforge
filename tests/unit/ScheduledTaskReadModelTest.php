<?php
declare(strict_types=1);
use DigiForge\Queue\ScheduledTaskReadModel;
use PHPUnit\Framework\TestCase;
if(is_file(__DIR__.'/../../includes/Queue/ScheduledTaskReadModel.php'))require_once __DIR__.'/../../includes/Queue/ScheduledTaskReadModel.php';
final class ScheduledTaskReadModelTest extends TestCase {
 public function testBoundedReadOnlyMetadataNeverLeaksRawArguments():void {
  $action=new class {function get_hook(){return 'digiforge_u3_build_product_stage';}function get_args(){return [2,'digital','secret-run-key','manifest_poll','api_key'=>'secret'];}};
  $model=new ScheduledTaskReadModel(static fn()=>[['id'=>9,'state'=>'PENDING','action'=>$action]],static fn()=>[]);$r=$model->snapshot(1);
  self::assertSame('AVAILABLE',$r['query_state']['action_scheduler']);self::assertCount(1,$r['items']);self::assertSame(2,$r['items'][0]['candidate_id']);self::assertSame(hash('sha256','secret-run-key'),$r['items'][0]['run_key_sha256']);self::assertStringNotContainsString('secret-run-key',json_encode($r));self::assertStringNotContainsString('api_key',json_encode($r));self::assertFalse($r['external_execution_authorized']);self::assertTrue($r['read_only']);
 }
 public function testUnavailableAndMalformedReadersCannotInferEmptyAvailableQueue():void {
  foreach([null, [['id'=>9,'state'=>'PENDING','action'=>new stdClass()]]]as $rows){$r=(new ScheduledTaskReadModel(static fn()=>$rows,static fn()=>null))->snapshot();self::assertSame('UNAVAILABLE',$r['query_state']['action_scheduler']);self::assertSame('UNAVAILABLE',$r['query_state']['wp_cron']);self::assertSame([],$r['items']);}
 }
 public function testOversizedEvidenceAndRunIdentityFailClosedBeforeProjection():void {
  $action=new class {function get_hook(){return 'digiforge_u3_build_product_stage';}function get_args(){return [2,'digital','fixture','manifest_poll'];}};
  $frame=['id'=>9,'state'=>'PENDING','action'=>$action];$r=(new ScheduledTaskReadModel(static fn()=>array_fill(0,7,$frame),static fn()=>[]))->snapshot(1);self::assertSame('UNAVAILABLE',$r['query_state']['action_scheduler']);self::assertSame([],$r['items']);
  $r=(new ScheduledTaskReadModel(static fn()=>[],static fn()=>array_fill(1,10001,[])))->snapshot();self::assertSame('UNAVAILABLE',$r['query_state']['wp_cron']);self::assertSame([],$r['items']);
  $long=new class {function get_hook(){return 'digiforge_u3_build_product_stage';}function get_args(){return [2,'digital',str_repeat('x',192),'manifest_poll'];}};$r=(new ScheduledTaskReadModel(static fn()=>[['id'=>9,'state'=>'PENDING','action'=>$long]],static fn()=>[]))->snapshot();self::assertSame('UNAVAILABLE',$r['query_state']['action_scheduler']);self::assertSame([],$r['items']);
 }

}
