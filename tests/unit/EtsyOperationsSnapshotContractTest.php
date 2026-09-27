<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyOperationsSnapshotContractTest extends TestCase {
 public function testSnapshotIsAggregateReadOnlyAndPrivacySafe():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Operations/EtsyOperationsSnapshot.php');foreach(['COUNT(*)','EtsyWebhookReadiness::inspect','contains_buyer_data','contains_secret_data',"'mutation_permitted'=>false","'external_execution_performed'=>false"] as $n)self::assertStringContainsString($n,$s);foreach(['buyer_reference','reconciliation_evidence','input_payload','wp_remote_','INSERT ','UPDATE ','DELETE '] as $n)self::assertStringNotContainsString($n,$s);}
 public function testEndpointRequiresOrderAndConnectionCapabilities():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/EtsyOperationsController.php');foreach(["'/etsy/operations'","'methods'=>'GET'","manage_digiforge_orders","manage_digiforge_connections"] as $n)self::assertStringContainsString($n,$s);}
}
