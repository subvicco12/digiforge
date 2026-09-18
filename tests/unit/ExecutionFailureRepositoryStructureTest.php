<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionFailureRepositoryStructureTest extends TestCase{
 public function testFailurePersistenceIsImmutableAndReplaySafe():void{
  $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionFailureRepository.php');self::assertIsString($s);
  foreach(['Tables::pod_execution_failures','authorization_hash','authorization_nonce_hash','failure_hash',"'retry_permitted']??null)!==false",'digiforge_failure_conflict',"['status'=>409]"] as $x)self::assertStringContainsString($x,$s);
  self::assertStringNotContainsString('wp_remote_',$s);
 }
}
