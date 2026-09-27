<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyOperationsSnapshotContractTest extends TestCase {
 public function testSnapshotIsAggregateReadOnlyAndPrivacySafe():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Operations/EtsyOperationsSnapshot.php');foreach(['COUNT(*)','EtsyWebhookReadiness::inspect','contains_buyer_data','contains_secret_data',"'mutation_permitted'=>false","'external_execution_performed'=>false",'orders_requiring_review','operations_requiring_reconciliation','operations_failed','operations_not_sent','attention_required','webhook_configuration_required','order_lines_without_provider_mapping','order_lines_with_ambiguous_provider_mapping','approved_plans_missing_readiness_evidence'] as $n)self::assertStringContainsString($n,$s);foreach(['buyer_reference','reconciliation_evidence','input_payload','wp_remote_','INSERT ','UPDATE ','DELETE '] as $n)self::assertStringNotContainsString($n,$s);}
 public function testEndpointRequiresOrderAndConnectionCapabilities():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/EtsyOperationsController.php');foreach(["'/etsy/operations'","'methods'=>'GET'","manage_digiforge_orders","manage_digiforge_connections"] as $n)self::assertStringContainsString($n,$s);}
}
