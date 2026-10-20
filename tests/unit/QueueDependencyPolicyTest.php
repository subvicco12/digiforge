<?php
declare(strict_types=1);
use DigiForge\Queue\QueueDependencyPolicy;
use PHPUnit\Framework\TestCase;
if(is_file(__DIR__.'/../../includes/Queue/QueueDependencyPolicy.php'))require_once __DIR__.'/../../includes/Queue/QueueDependencyPolicy.php';
final class QueueDependencyPolicyTest extends TestCase {
 public function testOnlyAuthoritativeSuccessfulDependenciesAreReady():void {
  self::assertTrue(QueueDependencyPolicy::evaluate(8,[2,3],[2=>['id'=>2,'state'=>'SUCCESS'],3=>['id'=>3,'state'=>'SUCCESS']])['dependencies_ready']);
  self::assertTrue(QueueDependencyPolicy::evaluate(8,[],[])['dependencies_ready']);
 }
 public function testUnfinishedDependencyWaitsAndTerminalFailureRequiresReview():void {
  foreach(['QUEUED','RUNNING','WAITING','RETRY','BLOCKED','HUMAN_REVIEW']as $state){$p=QueueDependencyPolicy::evaluate(8,[2],[2=>['id'=>2,'state'=>$state]]);self::assertFalse($p['dependencies_ready']);self::assertSame('WAITING',$p['state']);}
  foreach(['FAILED','CANCELLED','DEAD_LETTER']as $state){$p=QueueDependencyPolicy::evaluate(8,[2],[2=>['id'=>2,'state'=>$state]]);self::assertFalse($p['dependencies_ready']);self::assertSame('HUMAN_REVIEW',$p['state']);}
 }
 public function testMissingUnknownMismatchedOrMalformedEvidenceFailsClosed():void {
  foreach([[8,[2],null],[8,[2],[]],[8,[2],[2=>['id'=>3,'state'=>'SUCCESS']]],[8,[2],[2=>['id'=>'2corrupt','state'=>'SUCCESS']]],[8,[2],[2=>['id'=>2,'state'=>'UNKNOWN']]],[8,[8],[8=>['id'=>8,'state'=>'SUCCESS']]],[8,[2,2],[2=>['id'=>2,'state'=>'SUCCESS']]],[8,['2'],[2=>['id'=>2,'state'=>'SUCCESS']]],[0,[],[]]]as $args){$p=QueueDependencyPolicy::evaluate(...$args);self::assertFalse($p['dependencies_ready']);self::assertSame('BLOCKED',$p['state']);self::assertFalse($p['external_execution_authorized']);}
 }
}
