<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Normalizes the governed Master 500 v2 migration sheets without promoting them. */
final class MasterCatalogV2MigrationContract {
 public const MASTER_REQUIRED=['V2 ID','Source DG ID','Family','Concept','Engine','Physical Product','Supplier Gate','Template State','Origin','Recommended Stage'];
 public const MIGRATION_REQUIRED=['Source DG ID','Wave','V1 Family','V1 Concept','Engine','Disposition','Migration Note','V2 Successor ID','Retain as Overlay/Test'];

 /** @return array{catalog_key:string,row_count:int,rows:list<array<string,string>>,migration:list<array<string,string>>,fingerprint:string,production_authority:bool,promotion_authorized:bool} */
 public static function normalize(array $masterHeaders,array $masterRows,array $migrationHeaders,array $migrationRows,string $catalogKey='digicraftifygoods-master-500-v2'):array {
  if($catalogKey!=='digicraftifygoods-master-500-v2')throw new \InvalidArgumentException('Master 500 v2 catalog identity is invalid');
  if($masterHeaders!==self::MASTER_REQUIRED)throw new \InvalidArgumentException('Master 500 v2 headers do not match the governed contract');
  if($migrationHeaders!==self::MIGRATION_REQUIRED)throw new \InvalidArgumentException('Master 500 v2 migration headers do not match the governed contract');
  if(!array_is_list($masterRows)||!array_is_list($migrationRows)||count($masterRows)!==500||count($migrationRows)!==500)throw new \InvalidArgumentException('Master 500 v2 requires exactly 500 master rows and 500 migration rows');
  $masters=[];$byV2=[];$sourceSeen=[];
  foreach($masterRows as $i=>$row){
   $item=self::row(self::MASTER_REQUIRED,$row,'Master 500 v2',($i+2));
   if(!preg_match('/^DG2-[0-9]{3}$/',$item['V2 ID']))throw new \InvalidArgumentException('Master 500 v2 ID is invalid');
   if(!in_array($item['Origin'],['V1 retained','V2 addition','V2 new family'],true))throw new \InvalidArgumentException('Master 500 v2 origin is invalid');
   $retained=$item['Origin']==='V1 retained';
   if(($retained&&!preg_match('/^DG-[0-9]{3}$/',$item['Source DG ID']))||(!$retained&&$item['Source DG ID']!==''))throw new \InvalidArgumentException('Master 500 v2 origin/source lineage is invalid');
   if(!in_array($item['Supplier Gate'],['PRINTIFY_PRIMARY','ROUTE_BY_BASE_PRODUCT','PROVIDER_RESEARCH_REQUIRED'],true))throw new \InvalidArgumentException('Master 500 v2 supplier gate is invalid');
   if(!in_array($item['Recommended Stage'],['Re-score','Provider + demand research','Deep research'],true))throw new \InvalidArgumentException('Master 500 v2 recommended stage is invalid');
   foreach(['Family','Concept','Engine','Physical Product','Supplier Gate','Template State','Origin','Recommended Stage'] as $f)if($item[$f]==='')throw new \InvalidArgumentException($f.' is required');
   if(isset($byV2[$item['V2 ID']])||($retained&&isset($sourceSeen[$item['Source DG ID']])))throw new \InvalidArgumentException('Master 500 v2 identities must be one-to-one');
   $byV2[$item['V2 ID']]=$item['Source DG ID'];if($retained)$sourceSeen[$item['Source DG ID']]=true;$masters[]=$item;
  }
  $migrations=[];$migrationSource=[];$successor=[];$kept=[];
  foreach($migrationRows as $i=>$row){
   $item=self::row(self::MIGRATION_REQUIRED,$row,'Master 500 v2 migration',($i+2));
   if(!preg_match('/^DG-[0-9]{3}$/',$item['Source DG ID']))throw new \InvalidArgumentException('Master 500 v2 migration identity is invalid');
   if(isset($migrationSource[$item['Source DG ID']]))throw new \InvalidArgumentException('Master 500 v2 migration identities must be one-to-one');
   if(!in_array($item['Disposition'],['KEEP','MERGE','DOWNGRADE'],true))throw new \InvalidArgumentException('Master 500 v2 migration disposition is invalid');
   foreach(['Wave','V1 Family','V1 Concept','Engine'] as $f)if($item[$f]==='')throw new \InvalidArgumentException($f.' is required');
   if($item['Disposition']==='KEEP'){
    $id=$item['V2 Successor ID'];
    if(!preg_match('/^DG2-[0-9]{3}$/',$id)||isset($successor[$id])||!isset($byV2[$id])||$byV2[$id]!==$item['Source DG ID'])throw new \InvalidArgumentException('Master 500 v2 migration lineage does not match the master sheet');
    $successor[$id]=true;$kept[$item['Source DG ID']]=true;
   }elseif($item['V2 Successor ID']!=='')throw new \InvalidArgumentException('Non-KEEP source cannot claim a direct successor');
   $migrationSource[$item['Source DG ID']]=true;$migrations[]=$item;
  }
  if(array_diff_key($sourceSeen,$kept)!==[]||array_diff_key($kept,$sourceSeen)!==[])throw new \InvalidArgumentException('Master 500 v2 migration lineage is incomplete');
  $json=json_encode([$catalogKey,$masters,$migrations],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  return ['catalog_key'=>$catalogKey,'row_count'=>500,'rows'=>$masters,'migration'=>$migrations,'fingerprint'=>hash('sha256',(string)$json),'production_authority'=>false,'promotion_authorized'=>false];
 }
 private static function row(array $headers,mixed $row,string $label,int $line):array {
  if(!is_array($row)||!array_is_list($row)||count($row)!==count($headers))throw new \InvalidArgumentException($label.' row shape is invalid at '.$line);
  $out=[];foreach($headers as $n=>$h){
   if(!is_string($row[$n])||strlen($row[$n])>4096||preg_match('//u',$row[$n])!==1)throw new \InvalidArgumentException($label.' cell is invalid at '.$line);
   $out[$h]=trim($row[$n]);
  }return $out;
 }
}
