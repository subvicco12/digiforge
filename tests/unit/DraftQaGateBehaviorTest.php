<?php
declare(strict_types=1);
use DigiForge\Listings\DraftQaGate;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/Listings/DraftQaGate.php';
if(!class_exists('WP_Error')){class WP_Error{public function __construct(...$args){}}}
final class DraftQaGateBehaviorTest extends TestCase {
 private function checks():array{return array_fill_keys(['title','description','seo','media','pricing','policy','personalization'],'PASS');}
 public function testCompletePassingAndExplicitlyWaivedChecksProduceEvidenceWithoutPublishAuthority():void {
  foreach(['PASS','WAIVED']as $status){$checks=$this->checks();$checks['personalization']=$status;$r=DraftQaGate::evaluate($checks);self::assertIsArray($r);self::assertTrue($r['passed']);self::assertSame('DRAFT_QA_PASSED',$r['state']);self::assertFalse($r['publish_authorized']);self::assertFalse($r['etsy_api_invoked']);self::assertFalse($r['external_execution_performed']);}
 }
 public function testEveryRequiredMissingOrUnknownCheckFailsClosed():void {
  foreach(array_keys($this->checks())as $name){$checks=$this->checks();unset($checks[$name]);self::assertInstanceOf(WP_Error::class,DraftQaGate::evaluate($checks));$checks[$name]='PENDING';self::assertInstanceOf(WP_Error::class,DraftQaGate::evaluate($checks));}
 }
 public function testEveryFailingRequiredCheckProducesExplicitFailedEvidence():void {
  foreach(array_keys($this->checks())as $name){$checks=$this->checks();$checks[$name]='FAIL';$r=DraftQaGate::evaluate($checks);self::assertIsArray($r);self::assertFalse($r['passed']);self::assertSame('DRAFT_QA_FAILED',$r['state']);self::assertSame([$name],$r['failed_checks']);self::assertFalse($r['publish_authorized']);}
 }
}
