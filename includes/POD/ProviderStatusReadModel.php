<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Consolidated read-only provider status evidence. Never provider/production authority. */
final class ProviderStatusReadModel {
 public function snapshot(int $limit=50):array {
  $limit=max(1,min(100,$limit));$unknown=(new PrintifyUnknownOperatorReadModel())->summary();$items=array_slice((array)($unknown['items']??[]),0,$limit);
  $items=array_map(static function(array $r):array{$r['read_only']=true;$r['provider_execution_authorized']=false;$r['production_authorized']=false;$r['retry_permitted']=false;return $r;},$items);
  return ['provider'=>'printify','unknown_outcomes'=>(int)($unknown['unknown_outcomes']??0),'unresolved_reconciliations'=>(int)($unknown['unresolved_reconciliations']??0),'items'=>$items,'read_only'=>true,'reconcile_before_retry'=>true,'provider_execution_authorized'=>false,'production_authorized'=>false,'retry_permitted'=>false];
 }
}