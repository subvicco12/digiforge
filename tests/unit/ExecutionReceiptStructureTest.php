<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionReceiptStructureTest extends TestCase{
 public function testReceiptLinksAuthorizationEvidenceAndNonceConsumption():void{$s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionReceipt.php');self::assertIsString($s);foreach(['ExecutionAuthorizationVerifier::verify',"'EXECUTION_RECORDED'","authorization_nonce_hash","external_reference","receipt_hash","'nonce_must_be_consumed'=>true"] as $x)self::assertStringContainsString($x,$s);self::assertStringNotContainsString('wp_remote_',$s);}
}
