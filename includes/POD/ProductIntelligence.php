<?php

declare(strict_types=1);
namespace DigiForge\POD;

/** Research evidence only. Never feeds production mappings or Finance. */
final class ProductIntelligence
{
    public const MAX_BYTES = 16777216;
    public const SECTIONS = ['concepts','lineage','matches','candidates','blueprints','shortlist','providers','variants','print_areas','size_guides','shipping','costs','gaps','coverage','batches','source_lineage'];

    public static function rowsHash(array $rows): string
    {
        // Converter uses the identical compact JSON representation; numbers are strings.
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function validate(array $packet): array
    {
        if (($packet['schema_version'] ?? null) !== 1 || ($packet['shop_key'] ?? '') !== 'personalized_pod' || ($packet['business_key'] ?? '') !== 'digicraftifygoods' || ($packet['catalog_key'] ?? '') !== MasterCatalogV2Reference::CATALOG_KEY) {
            throw new \InvalidArgumentException('Exact Shop 2 research schema and catalog lineage required.');
        }
        if (strlen(json_encode($packet, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) throw new \InvalidArgumentException('Snapshot exceeds 16 MiB.');
        $sources = [];
        if (!is_array($packet['sources'] ?? null) || count($packet['sources']) > 50) throw new \InvalidArgumentException('Bounded source manifest required.');
        foreach ($packet['sources'] as $source) {
            if (!is_array($source) || !is_string($source['id'] ?? null) || $source['id'] === '' || isset($sources[$source['id']]) || !is_string($source['name'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', (string)($source['sha256'] ?? '')) || !is_string($source['modified_at'] ?? null) || strtotime($source['modified_at']) === false) throw new \InvalidArgumentException('Source ID, hash and timestamp required.');
            $sources[$source['id']] = $source;
        }
        if (!is_array($packet['sections'] ?? null) || !isset($packet['sections']['concepts'])) throw new \InvalidArgumentException('Concept source required.');
        $count = 0;
        foreach ($packet['sections'] as $key => $section) {
            if (!in_array($key, self::SECTIONS, true) || !is_array($section) || !isset($sources[$section['source_id'] ?? '']) || !is_string($section['sheet'] ?? null) || !is_array($section['rows'] ?? null) || !array_is_list($section['rows'])) throw new \InvalidArgumentException('Unsupported section or missing source binding.');
            $rows = $section['rows'];$count += count($rows);
            if ($count > 50000) throw new \InvalidArgumentException('Snapshot row limit exceeded.');
            foreach ($rows as $row) {
                if (!is_array($row) || count($row) > 64 || $row === []) throw new \InvalidArgumentException('Bounded named cells required.');
                foreach ($row as $column => $cell) {
                    if (!is_string($column) || strlen($column) > 120 || (!is_null($cell) && !is_string($cell)) || (is_string($cell) && strlen($cell) > 65536)) throw new \InvalidArgumentException('Cells must be strings or explicit null.');
                }
            }
            if (!hash_equals(self::rowsHash($rows), (string)($section['rows_sha256'] ?? ''))) throw new \InvalidArgumentException('Section row integrity mismatch.');
        }
        $identities=[];
        foreach ($packet['sections']['concepts']['rows'] as $row) {
            $id=$row['V2 ID']??'';
            if (!preg_match('/^DG2-[0-9]{3}$/D',$id) || isset($identities[$id]) || (!preg_match('/^PP-[0-9]{3}$/D',$row['Physical Product']??'') && ($row['Physical Product']??'')!=='V2-PRODUCT-RESEARCH')) throw new \InvalidArgumentException('Original unique DG2 and PP identity required.');
            $parent=$row['Source DG ID']??null;
            if ($parent !== null && $parent !== '' && !preg_match('/^DG-[0-9]{3}$/D',$parent)) throw new \InvalidArgumentException('Invalid source DG identity.');
            $identities[$id]=$row;
        }
        if ($identities === [] || count($identities)>500) throw new \InvalidArgumentException('One to 500 concepts required; partial imports stay explicit.');
        foreach (['matches','candidates','costs','gaps'] as $section) {
            foreach ($packet['sections'][$section]['rows']??[] as $row) {
                if (isset($row['V2 ID'])) {
                    if (!isset($identities[$row['V2 ID']])) throw new \InvalidArgumentException('Unknown linked concept.');
                    $concept=$identities[$row['V2 ID']];
                    foreach (['Source DG ID'=>'Source DG ID','Source ID'=>'Source DG ID','Physical Product'=>'Physical Product'] as $field=>$canonical) {
                        $value=$row[$field]??null;
                        if ($value!==null && $value!=='' && $value!==($concept[$canonical]??null)) throw new \InvalidArgumentException('Conflicting concept lineage requires reconciliation.');
                    }
                }
            }
        }
        // No caller authority fields survive validation.
        return ['schema_version'=>1,'shop_key'=>'personalized_pod','business_key'=>'digicraftifygoods','catalog_key'=>MasterCatalogV2Reference::CATALOG_KEY,'sources'=>array_values($sources),'sections'=>$packet['sections'],'approval_status'=>'UNREVIEWED','source_authenticity'=>'UNVERIFIED','production_authority'=>false,'finance_authority'=>false,'external_execution_authorized'=>false];
    }

    public static function page(array $packet, string $section, string $query='', int $page=1): array
    {
        $rows=$packet['sections'][$section]['rows']??[];
        $query=substr($query,0,120);
        if ($query!=='') $rows=array_values(array_filter($rows,static fn(array $row):bool=>stripos(implode(' ',array_map(static fn($v):string=>$v??'',$row)),$query)!==false));
        $total=count($rows);$pages=max(1,(int)ceil($total/25));$page=max(1,min($pages,$page));
        return ['rows'=>array_slice($rows,($page-1)*25,25),'total'=>$total,'page'=>$page,'pages'=>$pages];
    }

    public static function csv(array $rows, array $provenance=[]): string
    {
        $stream=fopen('php://temp','w+');if ($stream===false) throw new \RuntimeException('Export unavailable.');
        $rows=array_map(static fn(array $row):array=>$provenance+$row,$rows);
        $columns=[];foreach ($rows as $row) foreach (array_keys($row) as $key) $columns[$key]=true;
        $safe=static function($value):string { $text=$value===null?'':(string)$value;return preg_match('/^[\x00-\x20]*[=+@\-]/',$text)?"'".$text:$text; };
        fputcsv($stream,array_map($safe,array_keys($columns)),',','"','');
        foreach ($rows as $row) fputcsv($stream,array_map(static fn($key)=>$safe($row[$key]??null),array_keys($columns)),',','"','');
        rewind($stream);$csv=stream_get_contents($stream);fclose($stream);return $csv===false?'':$csv;
    }

    public static function batch(array $ids, int $limit=10): array
    {
        if ($limit<1 || $limit>20) throw new \InvalidArgumentException('Batch limit must be 1–20.');
        foreach ($ids as $id) if (!is_int($id) || $id<1) throw new \InvalidArgumentException('Positive blueprint IDs required.');
        $ids=array_values(array_unique($ids));if (count($ids)>$limit) throw new \InvalidArgumentException('Batch ceiling exceeded.');
        return ['state'=>'PLAN_ONLY','blueprint_ids'=>$ids,'limit'=>$limit,'external_execution_authorized'=>false,'grant_issued'=>false,'reservations_created'=>false,'executor'=>'EXISTING_AUTHENTICATED_WORDPRESS_CATALOG_READ_ONLY','blocker'=>'SEPARATE_APPROVAL_AND_EXISTING_GRANT_SAFETY_RESERVATION_IDEMPOTENCY_AUDIT_REQUIRED'];
    }
}
