<?php
declare(strict_types=1);
namespace DigiForge\Operations;
use DigiForge\Database\Tables;
use DigiForge\Listings\EtsyWebhookReadiness;

/** Aggregate counts only; no buyer data, secrets, payloads or mutation authority. */
final class EtsyOperationsSnapshot {
 /** @return array<string,mixed> */
 public static function inspect():array {
  global $wpdb;
  $orderCounts=[];foreach(['RECEIVED','VALIDATED','REVIEW_REQUIRED','APPROVED','ON_HOLD','REJECTED'] as $state)$orderCounts[$state]=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::orders().' WHERE channel=%s AND environment=%s AND state=%s','etsy','production',$state));
  $opTable=$wpdb->prefix.'digiforge_etsy_operations';$operationCounts=[];foreach(['NOT_SENT','SENT','CONFIRMED_SUCCESS','CONFIRMED_FAILURE','UNKNOWN','RECONCILIATION','RECONCILED'] as $state)$operationCounts[$state]=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$opTable} WHERE state=%s",$state));
  $exceptions=['orders_requiring_review'=>($orderCounts['REVIEW_REQUIRED']??0)+($orderCounts['ON_HOLD']??0),'operations_requiring_reconciliation'=>($operationCounts['UNKNOWN']??0)+($operationCounts['RECONCILIATION']??0),'operations_failed'=>$operationCounts['CONFIRMED_FAILURE']??0,'operations_not_sent'=>$operationCounts['NOT_SENT']??0];
  $webhook=EtsyWebhookReadiness::inspect();
  $exceptions['webhook_configuration_required']=($webhook['state']??'')!=='READY';
  $unmapped=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::order_line_items().' li INNER JOIN '.Tables::orders()." o ON o.id=li.order_id WHERE o.channel='etsy' AND o.environment='production' AND li.provider_mapping_id=0");
  $exceptions['order_lines_without_provider_mapping']=$unmapped;
  $exceptions['attention_required']=array_sum(array_map(static fn($v):int=>$v?1:0,$exceptions))>0;
  return ['state'=>'ETSY_OPERATIONS_SNAPSHOT','webhook'=>$webhook,'orders'=>$orderCounts,'operations'=>$operationCounts,'exceptions'=>$exceptions,'contains_buyer_data'=>false,'contains_secret_data'=>false,'mutation_permitted'=>false,'external_execution_performed'=>false];
 }
}
