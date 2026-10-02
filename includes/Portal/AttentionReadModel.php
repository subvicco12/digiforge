<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Database\Tables;

/** Read-only aggregation of explicit human gates and operational attention. */
final class AttentionReadModel
{
    /** @return array<string,mixed> */
    public function summary():array
    {
        global $wpdb;
        $count=static function(string $sql)use($wpdb):?int{$wpdb->last_error='';$value=$wpdb->get_var($sql);return $value===null||!empty($wpdb->last_error)?null:(int)$value;};
        $items=[
            'research_reviews'=>$count($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::research_candidates().' WHERE review_status=%s',\DigiForge\Research\Repository::REVIEW_PENDING)),
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
        $items['printify_unknown_reconciliations']=$reconciliation['unresolved_reconciliations']??null;
        $items['printify_reconciliation_reviews']=$reconciliation['resolved_review_required']??null;
        $items['etsy_operations_requiring_reconciliation']=$count("SELECT COUNT(*) FROM ".$wpdb->prefix.'digiforge_etsy_operations'." WHERE state IN ('UNKNOWN','RECONCILIATION')");
        $persistence=\DigiForge\POD\ProductionPermitPersistenceObservationRepository::summary();$persistenceDrilldown=\DigiForge\POD\ProductionPermitPersistenceObservationRepository::recent(50);$items['production_permit_persistence_unknown']=(int)$persistence['unknown_count'];$items['production_permit_persistence_observed']=(int)$persistence['persisted_observed_count'];
        $lifecycleClosures=$count("SELECT COUNT(*) FROM ".Tables::pod_lifecycle_closures());
        $items['execution_outcomes_pending']=$count("SELECT COUNT(*) FROM ".Tables::pod_execution_nonces()." n LEFT JOIN ".Tables::pod_execution_outcomes()." o ON o.authorization_hash=n.authorization_hash WHERE o.id IS NULL");
        $items['production_legacy_unbound']=$count("SELECT COUNT(*) FROM ".Tables::pod_execution_nonces()." n LEFT JOIN ".Tables::pod_authorization_bindings()." b ON b.authorization_hash=n.authorization_hash LEFT JOIN ".Tables::pod_lifecycle_closures()." c ON c.authorization_hash=n.authorization_hash WHERE b.id IS NULL AND c.id IS NULL");
        $integrity=(new \DigiForge\POD\ProductionProvenanceIntegrityReadModel())->recent(200);$integrityAvailable=(string)($integrity['query_state']??'UNAVAILABLE')==='AVAILABLE';$items['production_provenance_integrity']=$integrityAvailable?(int)$integrity['open_count']:null;$items['production_provenance_integrity_historical']=$integrityAvailable?(int)$integrity['historical_count']:null;$items['production_provenance_integrity_acknowledged']=$integrityAvailable?(int)$integrity['acknowledged_count']:null;
        $coverage=(new \DigiForge\POD\ProductionProvenanceIntegrityCoverageReadModel())->inspect((int)$integrity['current_count'],(int)$integrity['historical_count']);
        $unavailableSignals=array_keys(array_filter($items,static fn($value):bool=>$value===null));
        if($lifecycleClosures===null)$unavailableSignals[]='production_lifecycle_closures';
        return $items+['total_attention'=>self::completeTotal($items,$unavailableSignals),'query_state'=>$unavailableSignals===[]?'AVAILABLE':'PARTIAL_UNAVAILABLE','unavailable_signals'=>$unavailableSignals,'total_attention_scope'=>'INCLUDES_BOUNDED_INTEGRITY_WINDOW','production_lifecycle_closures'=>$lifecycleClosures,'production_provenance_integrity_projection'=>$integrity,'production_provenance_integrity_coverage'=>$coverage,'production_permit_persistence_projection'=>$persistence,'production_permit_persistence_drilldown'=>$persistenceDrilldown,'production_preflight'=>$preflight,'printify_reconciliation'=>$reconciliation,'reconciliation_guidance'=>['unknown_outcome'=>'RECONCILE_BEFORE_ANY_RETRY','failed_or_blocked_queue'=>'INSPECT_EVIDENCE_BEFORE_OPERATOR_ACTION','retry_permitted'=>false,'external_execution_authorized'=>false],'external_execution_state'=>'READ_ONLY_NO_EXECUTION','external_execution_performed'=>null];
    }
    /** An incomplete set cannot claim an authoritative total. */
    public static function completeTotal(array $items,array $unavailableSignals):?int
    {
        return $unavailableSignals===[]?self::currentTotal($items):null;
    }
    /** Historical and acknowledged evidence remains visible without inflating current work. */
    public static function currentTotal(array $items):int
    {
        unset($items['production_permit_persistence_observed'],$items['production_provenance_integrity_historical'],$items['production_provenance_integrity_acknowledged']);
        return array_sum($items);
    }
}
