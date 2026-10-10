<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\POD\TemplateCyclePolicy;
require_once __DIR__.'/../../includes/POD/TemplateCyclePolicy.php';
final class TemplateCyclePolicyTest extends TestCase {
 public function testBoundaryIsHalfOpenAndUtcIndependent():void {$p=['limit'=>8,'duration_seconds'=>604800,'anchor_utc'=>'2026-10-05 00:00:00'];$before=TemplateCyclePolicy::at($p,strtotime('2026-10-11 23:59:59 UTC'));$at=TemplateCyclePolicy::at($p,strtotime('2026-10-12 00:00:00 UTC'));self::assertSame('2026-10-05 00:00:00',$before['starts_at']);self::assertSame('2026-10-12 00:00:00',$before['ends_at']);self::assertSame($before['ends_at'],$at['starts_at']);self::assertNotSame($before['cycle_id'],$at['cycle_id']);$tz=date_default_timezone_get();try{date_default_timezone_set('Asia/Kolkata');self::assertSame($at,TemplateCyclePolicy::at($p,strtotime('2026-10-12 00:00:00 UTC')));}finally{date_default_timezone_set($tz);}}
 public function testChangingLimitDoesNotResetCycleIdentity():void {$p=['limit'=>8,'duration_seconds'=>604800,'anchor_utc'=>'2026-01-05 00:00:00'];$a=TemplateCyclePolicy::at($p,time());$p['limit']=10;self::assertSame($a['cycle_id'],TemplateCyclePolicy::at($p,time())['cycle_id']);}
 public function testInvalidCalendarAnchorIsRejected():void {$this->expectException(InvalidArgumentException::class);TemplateCyclePolicy::at(['limit'=>8,'duration_seconds'=>604800,'anchor_utc'=>'2026-02-30 00:00:00'],time());}
 public function testFormPreservesAnExplicitCapAndRejectsFractionalLimits():void {$this->assertTrue(method_exists(TemplateCyclePolicy::class,'fromForm'));$r=TemplateCyclePolicy::fromForm(['template_cycle_limit'=>'8','template_cycle_seconds'=>'604800','template_cycle_anchor'=>'2026-01-05 00:00:00']);self::assertSame(8,$r['limit']);self::assertNull(TemplateCyclePolicy::fromForm([]));$this->expectException(InvalidArgumentException::class);TemplateCyclePolicy::fromForm(['template_cycle_limit'=>'8.5','template_cycle_seconds'=>'604800','template_cycle_anchor'=>'2026-01-05 00:00:00']);}
}
