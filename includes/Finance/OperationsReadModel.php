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
  $validHash=static function(string $storedHash,array $canonical):bool{
   return preg_match('/^[a-f0-9]{64}$/',$storedHash)===1
    && hash_equals($storedHash,Validator::hash($canonical));
  };
  $normalizePeriod=static function(array $row)use($validHash):array{
   $metrics=json_decode((string)($row['metrics']??''),true);
   $valid=is_array($metrics)&&$validHash((string)($row['metrics_hash']??''),[
    'environment'=>(string)($row['environment']??''),
    'period_start'=>(string)($row['period_start']??''),
    'period_end'=>(string)($row['period_end']??''),
    'base_currency'=>(string)($row['base_currency']??''),
    'metrics'=>$metrics,
    'calculation_version'=>(string)($row['calculation_version']??''),
   ]);
   unset($row['metrics']);$row['metrics_valid']=$valid;$row['metrics']=$valid?$metrics:[];return $row;
  };
  $normalizeAnalytics=static function(array $row)use($validHash):array{
   $metrics=json_decode((string)($row['metrics']??''),true);
   $valid=is_array($metrics)&&$validHash((string)($row['metrics_hash']??''),$metrics);
   unset($row['metrics']);$row['metrics_valid']=$valid;$row['metrics']=$valid?$metrics:[];return $row;
  };
  return ['query_state'=>['periods'=>$periodsOk?'AVAILABLE':'UNAVAILABLE','analytics'=>$analyticsOk?'AVAILABLE':'UNAVAILABLE'],'periods'=>$periodsOk?array_map($normalizePeriod,$periods):[],'analytics'=>$analyticsOk?array_map($normalizeAnalytics,$analytics):[],'money_movement_authorized'=>false,'tax_filing_authorized'=>false,'external_execution_authorized'=>false];
 }
}
