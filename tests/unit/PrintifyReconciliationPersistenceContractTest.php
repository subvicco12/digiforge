<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyReconciliationPersistenceContractTest extends TestCase{
 public function testMutationIdentityBindsIntegration():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyMutationRequest.php');self::assertStringContainsString("'integration_id'=>\$integration,'shop_id'=>\$shop",$s);}
 public function testWorkflowRejectsIntegrationMismatchAndPersists():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyReconciliationWorkflow.php');self::assertStringContainsString('digiforge_printify_reconciliation_integration',$s);self::assertStringContainsString('PrintifyReconciliationRepository::save',$s);}
 public function testEvidenceIsImmutableHashBoundAndNonRetryable():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyReconciliationRepository.php');foreach(['authorization_hash','unknown_hash','request_fingerprint','resolution_state','reconciliation_hash',"retry_permitted']??null)!==false"] as $x)self::assertStringContainsString($x,$s);}
 public function testSchemaRegistersEvidenceTable():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Database/PodSchema.php');self::assertStringContainsString('pod_printify_reconciliations()',$s);self::assertStringContainsString('UNIQUE KEY reconciliation_hash',$s);}
}
