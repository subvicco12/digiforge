<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionOrchestratorStructureTest extends TestCase{
 public function testPreparationCannotBypassControlledGate():void{$s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionOrchestrator.php');self::assertIsString($s);foreach(['ControlledExecutionGate::authorize',"'ADAPTER_CALL_PERMITTED'","'nonce_consumed'","'ADAPTER_INVOCATION_PREPARED'","'adapter_invoked'=>false","'external_execution_performed'=>false"] as $x)self::assertStringContainsString($x,$s);self::assertStringNotContainsString('wp_remote_',$s);}
}
