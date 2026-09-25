<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;

/** Pure read model for persisted Digital Factory operational statuses. */
final class OperationalAdminSummary {
    /** @param array<int,mixed> $rows @return array<string,mixed> */
    public static function summarize(array $rows, string $field): array {
        if(!in_array($field,['status','generation_status'],true)){
            return ['total'=>count($rows),'states'=>[],'invalid'=>count($rows),'readiness_inferred'=>false,'external_actions_performed'=>false];
        }
        $states=[];$invalid=0;
        foreach($rows as $row){
            if(!is_array($row)||!isset($row[$field])||!is_string($row[$field])){ $invalid++; continue; }
            $state=strtoupper(trim($row[$field]));
            if($state===''||preg_match('/^[A-Z][A-Z0-9_]{0,31}$/',$state)!==1){ $invalid++; continue; }
            $states[$state]=($states[$state]??0)+1;
        }
        ksort($states);
        return ['total'=>count($rows),'states'=>$states,'invalid'=>$invalid,'readiness_inferred'=>false,'external_actions_performed'=>false];
    }
}
