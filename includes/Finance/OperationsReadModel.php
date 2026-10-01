<?php
declare(strict_types=1);
namespace DigiForge\Finance;
use DigiForge\Database\Tables;

/** Read-only, bounded finance/analytics operator projection. */
final class OperationsReadModel
{
 public function snapshot(int $limit=25):array{
  global $wpdb;$limit=max(1,min(50,$limit));
  $wpdb->last_error='';
  $periods=$wpdb->get_results($wpdb->prepare('SELECT id,environment,period_start,period_end,base_currency,metrics,metrics_hash,calculation_version,state,approved_by,approved_at,updated_at FROM '.Tables::finance_periods().' ORDER BY id DESC LIMIT %d',$limit),ARRAY_A);
  $periodsOk=is_array($periods)&&empty($wpdb->last_error);
  $wpdb->last_error='';
  $analytics=$wpdb->get_results($wpdb->prepare('SELECT id,environment,dimension_type,dimension_id,period_start,period_end,metrics,metrics_hash,freshness_metadata,calculation_version,created_at FROM '.Tables::analytics_snapshots().' ORDER BY id DESC LIMIT %d',$limit),ARRAY_A);
  $analyticsOk=is_array($analytics)&&empty($wpdb->last_error);
  $normalize=static function(array $row):array{$raw=(string)($row['metrics']??'');$metrics=json_decode($raw,true);$canonical=is_array($metrics)?wp_json_encode($metrics):false;$valid=is_string($canonical)&&preg_match('/^[a-f0-9]{64}$/',(string)($row['metrics_hash']??''))===1&&hash_equals((string)$row['metrics_hash'],hash('sha256',$canonical));unset($row['metrics']);$row['metrics_valid']=$valid;$row['metrics']=$valid?$metrics:[];return $row;};
  return ['query_state'=>['periods'=>$periodsOk?'AVAILABLE':'UNAVAILABLE','analytics'=>$analyticsOk?'AVAILABLE':'UNAVAILABLE'],'periods'=>$periodsOk?array_map($normalize,$periods):[],'analytics'=>$analyticsOk?array_map($normalize,$analytics):[],'money_movement_authorized'=>false,'tax_filing_authorized'=>false,'external_execution_authorized'=>false];
 }
}
