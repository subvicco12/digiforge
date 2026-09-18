<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionAuthorizationVerifierStructureTest extends TestCase{
 public function testVerifierChecksScopeEvidenceExpiryReplayAndIntegrity():void{$s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionAuthorizationVerifier.php');self::assertIsString($s);foreach(["'EXECUTION_AUTHORIZED'","'executed'","hash_equals","nonceUnused","900","digiforge_execution_replay","digiforge_execution_tampered"] as $x)self::assertStringContainsString($x,$s);self::assertStringNotContainsString('wp_remote_',$s);}
}
