<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class AdapterAttemptEvidenceFailClosedTest extends TestCase {
 public function testOnlyExplicitFalseMayClassifyAdapterErrorAsPreSend():void {
  $s=(string)file_get_contents(__DIR__.'/../../includes/POD/ExecutionAdapterFailure.php');
  self::assertStringContainsString("(\$data['network_request_attempted']??null)!==false",$s);
  self::assertStringContainsString("?'UNKNOWN':'FAILED'",$s);
  self::assertStringContainsString("?'AMBIGUOUS_TRANSPORT':'ADAPTER_ERROR'",$s);
  self::assertStringNotContainsString("network_request_attempted']??false",$s);
 }
}
