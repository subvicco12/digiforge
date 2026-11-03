<?php
declare(strict_types=1);
use DigiForge\Queue\QueueFailurePolicy;
use PHPUnit\Framework\TestCase;
if(is_file(__DIR__.'/../../includes/Queue/QueueFailurePolicy.php'))require_once __DIR__.'/../../includes/Queue/QueueFailurePolicy.php';
final class QueueFailurePolicyTest extends TestCase {
 public function testExplicitKnownRateLimitCanPrepareBoundedRetryWithoutAuthority():void {
  $p=QueueFailurePolicy::evaluate('digiforge_launch_ai_rate_limited',['retry_permitted'=>true],0,3,60);self::assertSame('RETRY',$p['state']);self::assertSame(1,$p['next_attempts']);self::assertSame(60,$p['delay_seconds']);self::assertFalse($p['external_execution_authorized']);self::assertFalse($p['automatic_retry_authorized']);
 }
 public function testExplicitNoRetryOrUnknownOutcomeAlwaysRequiresReview():void {
  foreach([[],['retry_permitted'=>false],['retry_permitted'=>true,'outcome'=>'UNKNOWN'],['retry_permitted'=>true,'provider_outcome'=>'UNKNOWN'],['retry_permitted'=>true,'outcome'=>'unknown'],['retry_permitted'=>true,'outcome'=>'SENT'],['retry_permitted'=>true,'provider_execution_performed'=>true]]as $data){$p=QueueFailurePolicy::evaluate('digiforge_launch_ai_rate_limited',$data,0,3,60);self::assertSame('HUMAN_REVIEW',$p['state']);self::assertFalse($p['retry_eligible']);}
 }
 public function testUnclassifiedTransportProviderQuotaAndStructuredFailureCannotInventRetry():void {
  foreach(['','unknown_fixture','digiforge_launch_ai_transport','digiforge_launch_ai_provider','digiforge_launch_ai_quota','digiforge_launch_ai_incomplete','digiforge_launch_ai_invalid_json']as $code)self::assertSame('HUMAN_REVIEW',QueueFailurePolicy::evaluate($code,['retry_permitted'=>true],0,3,60)['state']);
 }
 public function testRecognizedClassRespectsAttemptsAndRequiresSuppliedFiniteBackoff():void {
  $p=QueueFailurePolicy::evaluate('digiforge_launch_ai_rate_limited',['retry_permitted'=>true],2,3,60);self::assertSame('DEAD_LETTER',$p['state']);self::assertFalse($p['retry_eligible']);
  foreach([[-1,3,60],[0,0,60],[0,3,0],[0,3,86401]]as $args)self::assertSame('HUMAN_REVIEW',QueueFailurePolicy::evaluate('digiforge_launch_ai_rate_limited',['retry_permitted'=>true],...$args)['state']);
 }
}
