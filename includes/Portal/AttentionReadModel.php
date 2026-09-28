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
            'ownership_reviews'=>$count("SELECT COUNT(*) FROM ".Tables::pod_business_mappings()." WHERE state='DRAFT'"),
            'render_reviews'=>$count("SELECT COUNT(*) FROM ".Tables::pod_render_evidence()." WHERE review_status='UNREVIEWED'"),
            'authorization_package_reviews'=>$count("SELECT COUNT(*) FROM ".Tables::pod_authorization_packages()." WHERE state='REVIEW_REQUIRED'"),
            'pod_decisions'=>$count("SELECT COUNT(*) FROM ".Tables::pod_readiness_reviews()." WHERE decision='PENDING'"),
            'fulfillment_decisions'=>$count("SELECT COUNT(*) FROM ".Tables::fulfillment_readiness_reviews()." WHERE decision='PENDING'"),
            'blocked_finance_intents'=>$count("SELECT COUNT(*) FROM ".Tables::finance_intents()." WHERE state='BLOCKED'"),
            'open_operational_alerts'=>$count("SELECT COUNT(*) FROM ".Tables::operational_alerts()." WHERE state NOT IN ('RESOLVED','DISMISSED','SUPERSEDED','CLOSED')"),
            'orders_needing_reconciliation'=>$count("SELECT COUNT(DISTINCT o.id) FROM ".Tables::orders()." o LEFT JOIN ".Tables::order_line_items()." li ON li.order_id=o.id WHERE o.state NOT IN ('CLOSED','REJECTED','SUPERSEDED') AND (li.id IS NULL OR li.validation_status<>'VALIDATED' OR (li.provider_mapping_id=0 AND NOT EXISTS (SELECT 1 FROM ".Tables::digital_products()." dp WHERE dp.product_version_id=li.product_version_id)))"),
        ];
        $preflight=(new \DigiForge\POD\ProductionPreflightAttentionReadModel())->summary();
        $items['production_revalidation_reviews']=(int)$preflight['revalidation_required'];
        $reconciliation=(new \DigiForge\POD\PrintifyUnknownOperatorReadModel())->summary();
        $items['printify_unknown_reconciliations']=(int)$reconciliation['unresolved_reconciliations'];
        $items['printify_reconciliation_reviews']=(int)$reconciliation['resolved_review_required'];
        $items['etsy_operations_requiring_reconciliation']=$count("SELECT COUNT(*) FROM ".$wpdb->prefix.'digiforge_etsy_operations'." WHERE state IN ('UNKNOWN','RECONCILIATION')");
        $persistence=\DigiForge\POD\ProductionPermitPersistenceObservationRepository::summary();$persistenceDrilldown=\DigiForge\POD\ProductionPermitPersistenceObservationRepository::recent(50);$items['production_permit_persistence_unknown']=(int)$persistence['unknown_count'];$items['production_permit_persistence_observed']=(int)$persistence['persisted_observed_count'];
        $lifecycleClosures=$count("SELECT COUNT(*) FROM ".Tables::pod_lifecycle_closures());
        $items['execution_outcomes_pending']=$count("SELECT COUNT(*) FROM ".Tables::pod_execution_nonces()." n LEFT JOIN ".Tables::pod_execution_outcomes()." o ON o.authorization_hash=n.authorization_hash WHERE o.id IS NULL");
        $items['production_legacy_unbound']=$count("SELECT COUNT(*) FROM ".Tables::pod_execution_nonces()." n LEFT JOIN ".Tables::pod_authorization_bindings()." b ON b.authorization_hash=n.authorization_hash LEFT JOIN ".Tables::pod_lifecycle_closures()." c ON c.authorization_hash=n.authorization_hash WHERE b.id IS NULL AND c.id IS NULL");
        $integrity=(new \DigiForge\POD\ProductionProvenanceIntegrityReadModel())->recent(200);$items['production_provenance_integrity']=(int)$integrity['open_count'];$items['production_provenance_integrity_historical']=(int)$integrity['historical_count'];$items['production_provenance_integrity_acknowledged']=(int)$integrity['acknowledged_count'];
        return $items+['total_attention'=>self::currentTotal($items),'production_lifecycle_closures'=>$lifecycleClosures,'production_provenance_integrity_projection'=>$integrity,'production_permit_persistence_projection'=>$persistence,'production_permit_persistence_drilldown'=>$persistenceDrilldown,'production_preflight'=>$preflight,'printify_reconciliation'=>$reconciliation,'reconciliation_guidance'=>['unknown_outcome'=>'RECONCILE_BEFORE_ANY_RETRY','failed_or_blocked_queue'=>'INSPECT_EVIDENCE_BEFORE_OPERATOR_ACTION','retry_permitted'=>false,'external_execution_authorized'=>false],'external_execution_state'=>'READ_ONLY_NO_EXECUTION','external_execution_performed'=>null];
    }
    /** Historical and acknowledged evidence remains visible without inflating current work. */
    public static function currentTotal(array $items):int
    {
        unset($items['production_permit_persistence_observed'],$items['production_provenance_integrity_historical'],$items['production_provenance_integrity_acknowledged']);
        return array_sum($items);
    }
}
