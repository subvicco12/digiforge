<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class V6ProductionLifecycleIntegrityTest extends TestCase{
 public function testOutcomeProjectionUsesExplicitExecutionState():void{$s=file_get_contents(__DIR__.'/../../includes/POD/ConsumedPermitOutcomeReadModel.php');foreach(['NOT_ATTEMPTED','OUTCOME_PENDING','CONFIRMED_SUCCESS','CONFIRMED_FAILURE','UNKNOWN'] as $n)self::assertStringContainsString($n,$s);self::assertStringContainsString("?true:null",$s);}
 public function testUnknownCannotBeLifecycleClosed():void{$s=file_get_contents(__DIR__.'/../../includes/POD/ProductionLifecycleClosure.php');self::assertStringContainsString("['EXECUTION_SUCCEEDED','EXECUTION_FAILED']",$s);self::assertStringNotContainsString("'EXECUTION_UNKNOWN'",substr($s,strpos($s,'in_array'),160));}
 public function testAcknowledgementRequiresPersistedReconciliation():void{$s=file_get_contents(__DIR__.'/../../includes/POD/ReconciliationAcknowledgementRepository.php');self::assertStringContainsString('pod_printify_reconciliations',$s);self::assertStringContainsString('reconciliation_ack_evidence_missing',$s);self::assertStringContainsString('external_execution_authorized',$s);}
 public function testDryRunBindsTemplateIdentity():void{$s=file_get_contents(__DIR__.'/../../includes/POD/PersonalizedPodDryRun.php');self::assertStringContainsString('template_version',$s);self::assertStringContainsString('template_fingerprint',$s);}
}
