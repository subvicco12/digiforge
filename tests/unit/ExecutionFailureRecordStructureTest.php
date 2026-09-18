<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionFailureRecordStructureTest extends TestCase{
 public function testFailureEvidenceIsHashBoundAndCannotAuthorizeRetry():void{
  $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionFailureRecord.php');self::assertIsString($s);
  foreach(['ADAPTER_CALL_PERMITTED','nonce_consumed','ExecutionAdapterResult::normalize',"'FAILED'",'authorization_hash','authorization_nonce_hash',"'retry_permitted'=>false",'failure_hash'] as $x)self::assertStringContainsString($x,$s);
  self::assertStringNotContainsString('wp_remote_',$s);self::assertStringNotContainsString('curl_init',$s);
 }
}
