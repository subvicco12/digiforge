<?php
declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;

/** Observation span of recorded integrity evidence; dates do not establish resolution. */
final class ProductionProvenanceIntegrityTurnoverReadModel
{
    public function byAuthorizationHash(string $authorizationHash): array
    {
        $base=['read_only'=>true,'resolution_inferred'=>false,
            'retry_permitted'=>false,'external_execution_authorized'=>false];
        if (!preg_match('/^[a-f0-9]{64}$/',$authorizationHash)) {
            return $base+['lookup_state'=>'INVALID_REFERENCE'];
        }
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare(
            'SELECT MIN(first_observed_at) AS first_observed_at, MAX(last_observed_at) AS last_observed_at, COUNT(DISTINCT anomaly_type) AS distinct_anomaly_types FROM '.Tables::pod_provenance_integrity_evidence().' WHERE authorization_hash=%s',
            $authorizationHash
        ),ARRAY_A);
        if (!is_array($row) || !empty($wpdb->last_error)) {
            return $base+['lookup_state'=>'QUERY_UNAVAILABLE'];
        }
        $types=(int)($row['distinct_anomaly_types']??0);
        return $base+['lookup_state'=>$types>0?'RECORDED_SPAN':'NO_RECORDED_HISTORY',
            'scope'=>'EXACT_AUTHORIZATION_RECORDED',
            'first_observed_at'=>$row['first_observed_at']??null,
            'last_observed_at'=>$row['last_observed_at']??null,
            'distinct_anomaly_types'=>$types];
    }
}
