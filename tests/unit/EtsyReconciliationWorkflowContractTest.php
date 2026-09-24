<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyReconciliationWorkflowContractTest extends TestCase
{
 private function source():string{return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationWorkflow.php');}
 public function testWorkflowReloadsPersistedOperationAndSerializesTransitions():void{$s=$this->source();foreach(['acquireExecutionLock','->find($operationId)','EtsyOperationLifecycle::UNKNOWN','EtsyReconciliationService','EtsyReconciliationLookupPlan','EtsyOperationLifecycle::RECONCILIATION','EtsyReconciliationResultService','releaseExecutionLock'] as $n)self::assertStringContainsString($n,$s);}
 public function testWorkflowCannotPerformHttpMutationRetryOrPublish():void{$s=$this->source();foreach(["'mutation_permitted'=>false","'external_retry_permitted'=>false","'automatic_retry_permitted'=>false","'network_request_permitted'=>false"] as $n)self::assertStringContainsString($n,$s);foreach(['wp_remote_','curl_','EtsyControlledHttpExecutor','EtsyScopedCredentialRetriever','etsy_publish','publishListing'] as $n)self::assertStringNotContainsString($n,$s);}
 public function testRecordRequiresBoundCompletedReadOnlyLookup():void{$s=$this->source();foreach(["ETSY_RECONCILIATION_LOOKUP_COMPLETED","(int)(\$lookup['operation_id']??0)!==\$operationId","'mutation_performed']??null)!==false","'external_retry_performed']??null)!==false","'automatic_retry_performed']??null)!==false"] as $n)self::assertStringContainsString($n,$s);}
}
