<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** Bounded read projection. No cached authority, store, operation discovery proxy or write actions. */
final class OperatorReadModel {
 public function __construct(private VersionedMutationHost $host){}
 public function snapshot(array $intents):array|\WP_Error {
  if(!array_is_list($intents)||count($intents)>25)return self::error();$items=[];$seen=[];
  foreach($intents as $i){if(!$i instanceof MutationIntent||isset($seen[$i->operationId]))return self::error();$seen[$i->operationId]=true;$row=$this->host->inspect($i);if(is_wp_error($row))return self::error();$items[]=$row;}
  usort($items,static fn($a,$b)=>strcmp($a['operation_id'],$b['operation_id']));return ['schema'=>'DF_OPERATION_VIEW_1','items'=>$items,'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
 }
 public function export(array $intents):string|\WP_Error {
  $s=$this->snapshot($intents);if(is_wp_error($s))return $s;$s['schema']='DF_OPERATION_EXPORT_1';$s['generated_at']=time();$s['export_hash']=MutationIntent::hash($s);return json_encode($s,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
 }
 private static function error():\WP_Error{return new \WP_Error('printify_operation_view_denied','Bounded authorized core operation evidence required.',['read_only'=>true,'external_execution_authorized'=>false]);}
}
