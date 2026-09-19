<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class IntegrationRestMutationBodyLimitTest extends TestCase{
 public function testIntegrationMutationsFailFastOnOversizedBodies():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/IntegrationsController.php');
  self::assertStringContainsString('MAX_BODY_BYTES = 65536',$s);
  self::assertStringContainsString('$request->get_body()',$s);
  self::assertStringContainsString("'payload_too_large'",$s);
  self::assertStringContainsString("['status' => 413]",$s);
  self::assertLessThan(strpos($s,"get_header('Idempotency-Key')"),strpos($s,'strlen($request->get_body())'));
 }
}
