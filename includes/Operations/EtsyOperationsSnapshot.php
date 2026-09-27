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
  return ['state'=>'ETSY_OPERATIONS_SNAPSHOT','webhook'=>EtsyWebhookReadiness::inspect(),'orders'=>$orderCounts,'operations'=>$operationCounts,'contains_buyer_data'=>false,'contains_secret_data'=>false,'mutation_permitted'=>false,'external_execution_performed'=>false];
 }
}
