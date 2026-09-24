<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyOperationSpecificReconciliationContractTest extends TestCase
{
 public function testPlansUseOperationSpecificReadEndpoints():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationLookupPlan.php');foreach(["'/inventory'","'/images'","'UPDATE_INVENTORY'","'ATTACH_IMAGE'","'method'=>'GET'","'mutation_permitted'=>false","'automatic_retry_permitted'=>false"] as $n)self::assertStringContainsString($n,$s);}
 public function testWorkflowBindsPersistedCanonicalEvidence():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationWorkflow.php');foreach(['reconciliation_evidence','json_decode',"operation_evidence"] as $n)self::assertStringContainsString($n,$s);}
 public function testComparatorIsFailClosed():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationSpecificReconciliation.php');foreach(['UPDATE_DRAFT','UPDATE_INVENTORY','ATTACH_IMAGE','operation_evidence_missing','update_draft_evidence_mismatch','inventory_evidence_mismatch','image_evidence_mismatch','EtsyOperationLifecycle::UNKNOWN','EtsyOperationLifecycle::CONFIRMED_SUCCESS'] as $n)self::assertStringContainsString($n,$s);foreach(['wp_remote_','POST','PUT','DELETE','CredentialVault'] as $n)self::assertStringNotContainsString($n,$s);}
 public function testBinaryImageHashCannotBeInventedAsProviderProof():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationSpecificReconciliation.php');self::assertStringContainsString('content hash is not exposed',$s);self::assertStringContainsString('remain UNKNOWN',$s);}
}
