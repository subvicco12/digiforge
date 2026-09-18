<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionAdapterContractStructureTest extends TestCase{
 public function testContractRequiresConsumedPermitAndNormalizesOutcome():void{$a=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionAdapter.php');$r=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionAdapterResult.php');self::assertStringContainsString('interface ExecutionAdapter',$a);foreach(["'ADAPTER_CALL_PERMITTED'","'nonce_consumed'","'SUCCEEDED','FAILED'","external_reference","authorization_hash"] as $x)self::assertStringContainsString($x,$r);self::assertStringNotContainsString('wp_remote_',$a.$r);}
}
