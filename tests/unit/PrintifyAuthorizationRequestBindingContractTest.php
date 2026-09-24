<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyAuthorizationRequestBindingContractTest extends TestCase{
 public function testProviderAuthorizationRequiresFingerprint():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionAuthorization.php');
  self::assertStringContainsString("str_starts_with(\$action,'PROVIDER_')",$s);
  self::assertStringContainsString("request_fingerprint",$s);
 }
 public function testVerifierChecksBoundFingerprintBeforeNonceConsumption():void{
  $v=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionAuthorizationVerifier.php');
  self::assertStringContainsString("hash_equals(\$expectedRequestFingerprint,\$boundFingerprint)",$v);
  $g=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ControlledExecutionGate.php');
  self::assertStringContainsString("'request_fingerprint'=>strtolower(trim(\$requestFingerprint))",$g);
 }
 public function testPrintifyTransportTrustsPermitNotCallerFingerprint():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyControlledTransport.php');
  self::assertStringContainsString("(\$permit['request_fingerprint']??'')",$s);
  self::assertStringNotContainsString("payload['approved_request_fingerprint']",$s);
 }
}
