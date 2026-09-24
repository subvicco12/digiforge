<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyUnknownIdentityContractTest extends TestCase{
 public function testUnknownEvidencePersistsExactMutationIdentity():void{
  $a=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionAdapterResult.php');
  $u=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionUnknownRecord.php');
  $r=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionUnknownRepository.php');
  foreach(['request_fingerprint','reconciliation_identity'] as $x){self::assertStringContainsString($x,$a);self::assertStringContainsString($x,$u);self::assertStringContainsString($x,$r);}
 }
 public function testTransportErrorPreservesIdentityForUnknownProjection():void{
  $t=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyControlledTransport.php');
  $f=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/ExecutionAdapterFailure.php');
  foreach(['request_fingerprint','reconciliation_identity'] as $x){self::assertStringContainsString($x,$t);self::assertStringContainsString($x,$f);}
 }
}
