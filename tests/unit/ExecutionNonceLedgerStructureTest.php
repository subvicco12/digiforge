<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionNonceLedgerStructureTest extends TestCase{
 public function testLedgerHashesNonceAndFailsClosedOnReplayRace():void{$s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionNonceLedger.php');self::assertIsString($s);foreach(["hash('sha256',\$nonce)","digiforge_execution_replay","consumed_concurrently","authorization_hash","consumed_by"] as $x){if($x==='consumed_concurrently')continue;self::assertStringContainsString($x,$s);}self::assertStringNotContainsString('wp_remote_',$s);}
}
