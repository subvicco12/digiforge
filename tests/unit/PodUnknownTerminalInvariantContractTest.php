<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PodUnknownTerminalInvariantContractTest extends TestCase{
 public function testTerminalRepositoriesCannotOverwriteUnknown():void{
  foreach(['ExecutionReceiptRepository.php','ExecutionFailureRepository.php'] as $file){
   $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/'.$file);
   self::assertStringContainsString('Tables::pod_execution_unknowns()',$s);
   self::assertStringContainsString('requires reconciliation',$s);
  }
 }
 public function testUnknownInsertRaceIsIdempotent():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionUnknownRepository.php');
  self::assertStringContainsString("SELECT * FROM '.\$table.' WHERE authorization_hash=%s LIMIT 1",$s);
  self::assertStringContainsString("hash_equals((string)\$winner['unknown_hash'],\$hash)",$s);
 }
}
