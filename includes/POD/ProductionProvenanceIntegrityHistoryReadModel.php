<?php
declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;

/** Recorded anomaly history for one authorization; presence does not imply a live anomaly. */
final class ProductionProvenanceIntegrityHistoryReadModel
{
    /** @param ?list<string> $liveCorrelationHashes Null means the live lookup is unavailable. */
    public function byAuthorizationHash(string $authorizationHash, ?array $liveCorrelationHashes=null, int $beforeId=0): array
    {
        $base=['items'=>[], 'preview_limit'=>20, 'read_only'=>true,
            'retry_permitted'=>false, 'external_execution_authorized'=>false];
        if (!preg_match('/^[a-f0-9]{64}$/',$authorizationHash)) {
            return $base+['lookup_state'=>'INVALID_REFERENCE'];
        }
        if ($beforeId<0) return $base+['lookup_state'=>'INVALID_CURSOR'];
        global $wpdb;
        $table=Tables::pod_provenance_integrity_evidence();
        $count=$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM '.$table.' WHERE authorization_hash=%s',$authorizationHash
        ));
        if ($count===null || !empty($wpdb->last_error)) {
            return $base+['lookup_state'=>'QUERY_UNAVAILABLE'];
        }
        $select='SELECT id,authorization_hash,correlation_hash,anomaly_type,first_observed_at,last_observed_at FROM '.$table.' WHERE authorization_hash=%s';
        $rows=$wpdb->get_results($beforeId>0
            ? $wpdb->prepare($select.' AND id<%d ORDER BY id DESC LIMIT %d',$authorizationHash,$beforeId,21)
            : $wpdb->prepare($select.' ORDER BY id DESC LIMIT %d',$authorizationHash,21),ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error)) {
            return $base+['lookup_state'=>'QUERY_UNAVAILABLE'];
        }
        $more=count($rows)>20;
        $page=array_slice($rows,0,20);
        $nextCursor=$more?(int)$page[count($page)-1]['id']:null;
        $items=[];
        foreach ($page as $row) {
            $items[]=['authorization_hash'=>(string)$row['authorization_hash'],
                'correlation_hash'=>(string)$row['correlation_hash'],
                'type'=>(string)$row['anomaly_type'],
                'first_observed_at'=>(string)$row['first_observed_at'],
                'last_observed_at'=>(string)$row['last_observed_at'],
                'retry_permitted'=>false,'external_execution_authorized'=>false];
        }
        $result=array_replace($base,['lookup_state'=>(int)$count>0?'RECORDED_HISTORY':'NO_RECORDED_HISTORY',
            'items'=>$items,'recorded_count'=>(int)$count,'count_scope'=>'EXACT_AUTHORIZATION_RECORDED',
            'preview_truncated'=>$more,'next_cursor'=>$nextCursor]);
        if ($liveCorrelationHashes===null) return $result;
        $live=array_values(array_unique($liveCorrelationHashes));
        if (count($live)>4 || array_filter($live,static fn($hash):bool=>!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/',$hash))) {
            return $result+['comparison_state'=>'INVALID_LIVE_REFERENCE'];
        }
        $overlap=0;
        if ($live!==[]) {
            $placeholders=implode(',',array_fill(0,count($live),'%s'));
            $overlap=$wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM '.$table.' WHERE authorization_hash=%s AND correlation_hash IN ('.$placeholders.')',
                $authorizationHash,...$live
            ));
            if ($overlap===null || !empty($wpdb->last_error)) {
                return $result+['comparison_state'=>'QUERY_UNAVAILABLE'];
            }
        }
        $liveSet=array_fill_keys($live,true);
        foreach ($result['items'] as &$item) {
            $item['comparison_state']=isset($liveSet[$item['correlation_hash']])?'ALSO_LIVE':'NOT_IN_LIVE_LOOKUP';
        }
        unset($item);
        $result['comparison_state']='COMPARED';
        $result['comparison_scope']='EXACT_AUTHORIZATION_RECORDED_VS_LIVE';
        $result['recorded_live_overlap_count']=(int)$overlap;
        $result['recorded_without_live_correlation_count']=(int)$count-(int)$overlap;
        return $result;
    }
}
