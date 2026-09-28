<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class GateNonceAvailabilityPropagationContractTest extends TestCase {
 public function testVerifierPropagatesNonceEvidenceErrors():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ExecutionAuthorizationVerifier.php');
  self::assertStringContainsString('$unused=$nonceUnused($nonce)',$c);
  self::assertStringContainsString('if(is_wp_error($unused))return $unused',$c);
  self::assertStringContainsString("if($unused!==true)",$c);
 }
 public function testGateCallbackAcceptsExplicitEvidenceError():void {
  $c=file_get_contents(__DIR__.'/../../includes/POD/ControlledExecutionGate.php');
  self::assertStringContainsString('bool|WP_Error=>ExecutionNonceLedger::unused($nonce)',$c);
 }
}
