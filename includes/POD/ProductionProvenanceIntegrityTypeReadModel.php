<?php
declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;

/** Constant-size, exact type counts for recorded evidence of one authorization. */
final class ProductionProvenanceIntegrityTypeReadModel
{
    private const TYPES=['BINDING_PACKAGE_MISMATCH','CLOSURE_PACKAGE_MISMATCH',
        'BINDING_CLOSURE_MISMATCH','LEGACY_UNBOUND'];

    public function byAuthorizationHash(string $authorizationHash): array
    {
        $base=['read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
        if (!preg_match('/^[a-f0-9]{64}$/',$authorizationHash)) {
            return $base+['lookup_state'=>'INVALID_REFERENCE'];
        }
        global $wpdb;
        $parts=['COUNT(*) AS recorded_count'];
        foreach (self::TYPES as $i=>$type) {
            $parts[]="SUM(CASE WHEN anomaly_type='".$type."' THEN 1 ELSE 0 END) AS type_".$i;
        }
        $row=$wpdb->get_row($wpdb->prepare(
            'SELECT '.implode(',',$parts).' FROM '.Tables::pod_provenance_integrity_evidence().' WHERE authorization_hash=%s',
            $authorizationHash
        ),ARRAY_A);
        if (!is_array($row) || !empty($wpdb->last_error)) {
            return $base+['lookup_state'=>'QUERY_UNAVAILABLE'];
        }
        if (!isset($row['recorded_count']) || !is_numeric($row['recorded_count'])) {
            return $base+['lookup_state'=>'INCONSISTENT_EVIDENCE'];
        }
        $count=(int)$row['recorded_count'];
        $types=[];
        foreach (self::TYPES as $i=>$type) {
            $value=$row['type_'.$i]??null;
            if ($count>0 && ($value===null || !is_numeric($value))) {
                return $base+['lookup_state'=>'INCONSISTENT_EVIDENCE'];
            }
            $types[$type]=(int)$value;
        }
        if ($count<0 || min($types)<0 || array_sum($types)>$count) {
            return $base+['lookup_state'=>'INCONSISTENT_EVIDENCE'];
        }
        $types['OTHER_RECORDED_TYPE']=$count-array_sum($types);
        return $base+['lookup_state'=>$count>0?'RECORDED_TYPES':'NO_RECORDED_HISTORY',
            'count_scope'=>'EXACT_AUTHORIZATION_RECORDED','recorded_count'=>$count,
            'type_counts'=>$types];
    }
}
