<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ControlledExecutionGateStructureTest extends TestCase{
 public function testGateVerifiesAndConsumesBeforeAdapterPermission():void{$s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ControlledExecutionGate.php');self::assertIsString($s);foreach(['ExecutionAuthorizationVerifier::verify','ExecutionNonceLedger::unused','ExecutionNonceLedger::consume',"'ADAPTER_CALL_PERMITTED'","'nonce_consumed'=>true","'external_execution_performed'=>false"] as $x)self::assertStringContainsString($x,$s);self::assertStringNotContainsString('wp_remote_',$s);}
}
