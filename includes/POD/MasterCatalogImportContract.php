<?php
declare(strict_types=1);
namespace DigiForge\POD;

final class MasterCatalogImportContract {
 public const REQUIRED=['Listing ID','Wave','Family','Concept','Engine','Physical Product','Supplier Gate','Template State','US Route','EU Route','Priority','Notes'];
 /** @return array{catalog_key:string,row_count:int,rows:list<array<string,string>>,fingerprint:string} */
 public static function normalize(array $headers,array $rows,string $catalogKey=PersonalizedCatalogReference::CATALOG_KEY):array {
  if($headers!==self::REQUIRED)throw new \InvalidArgumentException('Master 500 headers do not match the governed contract');
  if(count($rows)!==PersonalizedCatalogReference::LISTING_COUNT)throw new \InvalidArgumentException('Master 500 must contain exactly 500 listing rows');
  $seen=[];$out=[];
  foreach($rows as $i=>$row){
   if(!is_array($row)||count($row)!==count(self::REQUIRED))throw new \InvalidArgumentException('Master 500 row shape is invalid at '.($i+2));
   $item=[];foreach(self::REQUIRED as $n=>$h)$item[$h]=trim((string)$row[$n]);
   if(!preg_match('/^DG-[0-9]{3}$/',$item['Listing ID']))throw new \InvalidArgumentException('Master 500 listing ID is invalid');
   if(isset($seen[$item['Listing ID']]))throw new \InvalidArgumentException('Master 500 listing IDs must be unique');
   foreach(['Family','Concept','Engine','Physical Product','Supplier Gate','Template State'] as $field)if($item[$field]==='')throw new \InvalidArgumentException($field.' is required');
   $seen[$item['Listing ID']]=true;$out[]=$item;
  }
  $json=json_encode([$catalogKey,$out],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  return ['catalog_key'=>$catalogKey,'row_count'=>count($out),'rows'=>$out,'fingerprint'=>hash('sha256',(string)$json)];
 }
}