<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionAdapterFailureStructureTest extends TestCase{
 public function testErrorProjectionExcludesProviderMessageAndData():void{
  // Persist only a sanitized failure code; never raw provider message or error data.
  $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionAdapterFailure.php');self::assertIsString($s);
  foreach(["'status'=>'FAILED'","'failure_category'=>'ADAPTER_ERROR'",'get_error_code','sanitize_key'] as $x)self::assertStringContainsString($x,$s);
  self::assertStringNotContainsString('get_error_message',$s);self::assertStringNotContainsString('get_error_data',$s);self::assertStringNotContainsString('wp_remote_',$s);
 }
}
