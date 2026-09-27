<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Database\Tables;

/** Read-only aggregation of explicit human gates and operational attention. */
final class AttentionReadModel
{
    /** @return array<string,int|bool> */
    public function summary():array
    {
        global $wpdb;
        $count=static function(string $sql)use($wpdb):int{return (int)$wpdb->get_var($sql);};
        $items=[
            'research_reviews'=>$count("SELECT COUNT(*) FROM ".Tables::research_candidates()." WHERE review_status='REVIEW_PENDING'"),
            'listing_decisions'=>$count("SELECT COUNT(*) FROM ".Tables::listing_readiness_reviews()." WHERE decision='PENDING'"),
            'personalization_reviews'=>$count("SELECT COUNT(*) FROM ".Tables::personalization_submissions()." WHERE review_status NOT IN ('APPROVED','REJECTED')"),
            'pod_decisions'=>$count("SELECT COUNT(*) FROM ".Tables::pod_readiness_reviews()." WHERE decision='PENDING'"),
            'fulfillment_decisions'=>$count("SELECT COUNT(*) FROM ".Tables::fulfillment_readiness_reviews()." WHERE decision='PENDING'"),
            'blocked_finance_intents'=>$count("SELECT COUNT(*) FROM ".Tables::finance_intents()." WHERE state='BLOCKED'"),
            'open_operational_alerts'=>$count("SELECT COUNT(*) FROM ".Tables::operational_alerts()." WHERE state NOT IN ('RESOLVED','CLOSED')"),
        ];
        return $items+['total_attention'=>array_sum($items),'external_execution_performed'=>false];
    }
}
