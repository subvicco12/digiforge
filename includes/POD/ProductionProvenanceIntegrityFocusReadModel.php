<?php
declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;

/** Exact, bounded live-anomaly lookup. Never observes, acknowledges or executes. */
final class ProductionProvenanceIntegrityFocusReadModel
{
    public function byAuthorizationHash(string $authorizationHash): array
    {
        $empty = ['items'=>[], 'read_only'=>true, 'retry_permitted'=>false, 'external_execution_authorized'=>false];
        if (!preg_match('/^[a-f0-9]{64}$/', $authorizationHash)) {
            return $empty + ['lookup_state'=>'INVALID_REFERENCE'];
        }
        global $wpdb;
        $queries = [
            'BINDING_PACKAGE_MISMATCH' => 'SELECT b.package_id,b.package_hash,p.package_hash AS actual_package_hash FROM '.Tables::pod_authorization_bindings().' b LEFT JOIN '.Tables::pod_authorization_packages().' p ON p.id=b.package_id WHERE b.authorization_hash=%s AND (p.id IS NULL OR p.package_hash<>b.package_hash) LIMIT 1',
            'CLOSURE_PACKAGE_MISMATCH' => 'SELECT c.package_id,c.package_hash,p.package_hash AS actual_package_hash FROM '.Tables::pod_lifecycle_closures().' c LEFT JOIN '.Tables::pod_authorization_packages().' p ON p.id=c.package_id WHERE c.authorization_hash=%s AND (p.id IS NULL OR p.package_hash<>c.package_hash) LIMIT 1',
            'BINDING_CLOSURE_MISMATCH' => 'SELECT b.package_id,b.package_hash,c.package_hash AS actual_package_hash FROM '.Tables::pod_authorization_bindings().' b INNER JOIN '.Tables::pod_lifecycle_closures().' c ON c.authorization_hash=b.authorization_hash WHERE b.authorization_hash=%s AND (b.package_id<>c.package_id OR b.package_hash<>c.package_hash) LIMIT 1',
            'LEGACY_UNBOUND' => 'SELECT 0 AS package_id,\'\' AS package_hash,NULL AS actual_package_hash FROM '.Tables::pod_execution_nonces().' n LEFT JOIN '.Tables::pod_authorization_bindings().' b ON b.authorization_hash=n.authorization_hash LEFT JOIN '.Tables::pod_lifecycle_closures().' c ON c.authorization_hash=n.authorization_hash WHERE n.authorization_hash=%s AND b.id IS NULL AND c.id IS NULL LIMIT 1',
        ];
        $rows=[];
        foreach ($queries as $type=>$sql) {
            $row=$wpdb->get_row($wpdb->prepare($sql,$authorizationHash),ARRAY_A);
            if (!empty($wpdb->last_error)) return $empty + ['lookup_state'=>'QUERY_UNAVAILABLE'];
            if (is_array($row)) $rows[$type]=$row;
        }
        if ($rows===[]) return $empty + ['lookup_state'=>'NO_LIVE_ANOMALY','open_count'=>0,'acknowledged_count'=>0,'count_scope'=>'EXACT_AUTHORIZATION_LIVE'];
        $outcome=$wpdb->get_row($wpdb->prepare('SELECT outcome_hash FROM '.Tables::pod_execution_outcomes().' WHERE authorization_hash=%s LIMIT 1',$authorizationHash),ARRAY_A);
        if (!empty($wpdb->last_error)) return $empty + ['lookup_state'=>'QUERY_UNAVAILABLE'];
        $closure=$wpdb->get_row($wpdb->prepare('SELECT closure_hash FROM '.Tables::pod_lifecycle_closures().' WHERE authorization_hash=%s LIMIT 1',$authorizationHash),ARRAY_A);
        if (!empty($wpdb->last_error)) return $empty + ['lookup_state'=>'QUERY_UNAVAILABLE'];
        $items=[];
        foreach ($rows as $type=>$row) {
            $correlation=hash('sha256',implode('|',[$type,$authorizationHash,(string)$row['package_id'],(string)$row['package_hash'],(string)($row['actual_package_hash']??''),(string)($outcome['outcome_hash']??''),(string)($closure['closure_hash']??'')]));
            $ack=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Tables::pod_provenance_integrity_acknowledgements().' WHERE correlation_hash=%s LIMIT 1',$correlation));
            if (!empty($wpdb->last_error)) return $empty + ['lookup_state'=>'QUERY_UNAVAILABLE'];
            $items[]=['type'=>$type,'authorization_hash'=>$authorizationHash,'correlation_hash'=>$correlation,'operator_state'=>$ack?'ACKNOWLEDGED':'OPEN','read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
        }
        $acknowledged=count(array_filter($items,static fn(array $item):bool=>$item['operator_state']==='ACKNOWLEDGED'));
        return array_replace($empty,['lookup_state'=>'LIVE_ANOMALIES','items'=>$items,'open_count'=>count($items)-$acknowledged,'acknowledged_count'=>$acknowledged,'count_scope'=>'EXACT_AUTHORIZATION_LIVE']);
    }
}
