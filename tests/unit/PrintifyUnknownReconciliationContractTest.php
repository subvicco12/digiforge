<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyUnknownReconciliationContractTest extends TestCase {
 public function testPersistedUnknownIsReloadedBeforeReadOnlyLookup():void {
  $w=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyReconciliationWorkflow.php');
  $l=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyReconciliationLookup.php');
  self::assertStringContainsString('ExecutionOutcomeRepository::findByAuthorizationHash',$w);
  self::assertStringContainsString("'EXECUTION_UNKNOWN'",$w);
  self::assertStringContainsString("'method'=>'GET'",$l);
  self::assertStringContainsString("'/orders.json'",$l);
  self::assertStringContainsString('PrintifyScopedCredentialRetriever',$l);
  self::assertStringContainsString("'redirection'=>0",$l);self::assertStringContainsString("'sslverify'=>true",$l);
  self::assertStringNotContainsString("'method'=>'POST'",$l);
 }
 public function testNoBlindRetryIsEverAuthorized():void {
  $l=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyReconciliationLookup.php');
  self::assertStringNotContainsString("'retry_permitted'=>true",$l);
  foreach(['PRINTIFY_RECONCILIATION_UNKNOWN','PRINTIFY_RECONCILIATION_NOT_CONFIRMED','PRINTIFY_RECONCILIATION_CONFIRMED','request_fingerprint','reconciliation_identity'] as $marker) self::assertStringContainsString($marker,$l);
  foreach(['sleep(','usleep(','wp_schedule_','as_schedule_','send_to_production.json'] as $marker) self::assertStringNotContainsString($marker,$l);
 }
}
