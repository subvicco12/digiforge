<?php
declare(strict_types=1);
namespace DigiForge\AI;

use DigiForge\Database\Tables;
use DigiForge\Database\QueryEvidence;

/** Read-only lifecycle counts; these are denominators, not cost attribution claims. */
final class LifecycleDenominatorReadModel
{
    /** Failed counts stay unknown; successful independent sources retain their values. */
    public function snapshot():array
    {
        global $wpdb;
        $count=static function(string $sql)use($wpdb):?int {
            $wpdb->last_error='';
            $raw=$wpdb->get_var($sql);
            if(!is_int($raw)&&!is_string($raw))return null;
            $value=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            return $value===false||QueryEvidence::failed()?null:$value;
        };
        $counts=[
            'opportunities'=>$count('SELECT COUNT(*) FROM '.Tables::opportunities()),
            'developed_products'=>$count('SELECT COUNT(*) FROM '.Tables::products()),
            'approved_listings'=>$count("SELECT COUNT(*) FROM ".Tables::listings()." WHERE state='APPROVED'"),
            'received_orders'=>$count('SELECT COUNT(*) FROM '.Tables::orders()),
        ];
        $states=array_map(static fn(?int $value):string=>$value===null?'UNAVAILABLE':'AVAILABLE',$counts);
        $available=!in_array('UNAVAILABLE',$states,true);
        return $counts+[
            'query_state'=>$available?'AVAILABLE':'UNAVAILABLE',
            'query_states'=>$states,
            'denominators_authoritative'=>$available,
            'ai_cost_attribution_established'=>false,
            'external_execution_performed'=>false,
        ];
    }
}
