<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionReceiptRepositoryStructureTest extends TestCase{
 public function testRepositoryUsesExactReplayAndImmutableBindings():void{$s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionReceiptRepository.php');self::assertIsString($s);foreach(["'EXECUTION_RECORDED'","authorization_hash","authorization_nonce_hash","receipt_hash","hash_equals","digiforge_receipt_conflict"] as $x)self::assertStringContainsString($x,$s);self::assertStringNotContainsString('wp_remote_',$s);}
}
