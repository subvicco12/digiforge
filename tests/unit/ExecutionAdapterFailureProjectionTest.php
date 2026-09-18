<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ExecutionAdapterFailureProjectionTest extends TestCase{
 public function testNormalizerPreservesOnlySanitizedFailureMetadata():void{
  $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionAdapterResult.php');self::assertIsString($s);
  foreach(['failure_category','failure_code','sanitize_key'] as $x)self::assertStringContainsString($x,$s);
  self::assertStringNotContainsString('get_error_message',$s);
  self::assertStringNotContainsString('get_error_data',$s);
 }
}
