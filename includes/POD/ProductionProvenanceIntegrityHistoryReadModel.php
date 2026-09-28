<?php
declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;

/** Recorded anomaly history for one authorization; presence does not imply a live anomaly. */
final class ProductionProvenanceIntegrityHistoryReadModel
{
    public function byAuthorizationHash(string $authorizationHash): array
    {
        $base=['items'=>[], 'preview_limit'=>20, 'read_only'=>true,
            'retry_permitted'=>false, 'external_execution_authorized'=>false];
        if (!preg_match('/^[a-f0-9]{64}$/',$authorizationHash)) {
            return $base+['lookup_state'=>'INVALID_REFERENCE'];
        }
        global $wpdb;
        $table=Tables::pod_provenance_integrity_evidence();
        $count=$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM '.$table.' WHERE authorization_hash=%s',$authorizationHash
        ));
        if ($count===null || !empty($wpdb->last_error)) {
            return $base+['lookup_state'=>'QUERY_UNAVAILABLE'];
        }
        $rows=$wpdb->get_results($wpdb->prepare(
            'SELECT authorization_hash,correlation_hash,anomaly_type,first_observed_at,last_observed_at FROM '.$table.' WHERE authorization_hash=%s ORDER BY id DESC LIMIT %d',
            $authorizationHash,20
        ),ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error)) {
            return $base+['lookup_state'=>'QUERY_UNAVAILABLE'];
        }
        $items=[];
        foreach ($rows as $row) {
            $items[]=['authorization_hash'=>(string)$row['authorization_hash'],
                'correlation_hash'=>(string)$row['correlation_hash'],
                'type'=>(string)$row['anomaly_type'],
                'first_observed_at'=>(string)$row['first_observed_at'],
                'last_observed_at'=>(string)$row['last_observed_at'],
                'retry_permitted'=>false,'external_execution_authorized'=>false];
        }
        return array_replace($base,['lookup_state'=>(int)$count>0?'RECORDED_HISTORY':'NO_RECORDED_HISTORY',
            'items'=>$items,'recorded_count'=>(int)$count,'count_scope'=>'EXACT_AUTHORIZATION_RECORDED',
            'preview_truncated'=>(int)$count>count($items)]);
    }
}
