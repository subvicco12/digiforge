<?php
declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;

/** Read-only candidate coverage. Raw source counts can overlap and never grant authority. */
final class ProductionProvenanceIntegrityCoverageReadModel
{
    public function inspect(int $visibleCurrent, int $visibleHistorical): array
    {
        global $wpdb;
        $queries = [
            'binding_package_mismatch' => 'SELECT COUNT(*) FROM ' . Tables::pod_authorization_bindings() . ' b LEFT JOIN ' . Tables::pod_authorization_packages() . ' p ON p.id=b.package_id WHERE p.id IS NULL OR p.package_hash<>b.package_hash',
            'closure_package_mismatch' => 'SELECT COUNT(*) FROM ' . Tables::pod_lifecycle_closures() . ' c LEFT JOIN ' . Tables::pod_authorization_packages() . ' p ON p.id=c.package_id WHERE p.id IS NULL OR p.package_hash<>c.package_hash',
            'binding_closure_mismatch' => 'SELECT COUNT(*) FROM ' . Tables::pod_authorization_bindings() . ' b INNER JOIN ' . Tables::pod_lifecycle_closures() . ' c ON c.authorization_hash=b.authorization_hash WHERE b.package_id<>c.package_id OR b.package_hash<>c.package_hash',
            'legacy_unbound' => 'SELECT COUNT(*) FROM ' . Tables::pod_execution_nonces() . ' n LEFT JOIN ' . Tables::pod_authorization_bindings() . ' b ON b.authorization_hash=n.authorization_hash LEFT JOIN ' . Tables::pod_lifecycle_closures() . ' c ON c.authorization_hash=n.authorization_hash WHERE b.id IS NULL AND c.id IS NULL',
        ];
        $counts = [];
        foreach ($queries as $name => $sql) {
            $wpdb->last_error = '';
            $value = $wpdb->get_var($sql);
            $counts[$name] = $value === null || !empty($wpdb->last_error) ? null : (int) $value;
        }
        $wpdb->last_error = '';
        $historical = $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::pod_provenance_integrity_evidence());
        $historical = $historical === null || !empty($wpdb->last_error) ? null : (int) $historical;
        $complete = $historical !== null && !in_array(null, $counts, true);
        $candidates = $complete ? array_sum($counts) : null;
        $possible = $complete ? ($candidates > max(0, $visibleCurrent) || $historical > max(0, $visibleHistorical)) : null;
        return [
            'source_candidate_rows' => $counts,
            'candidate_rows' => $candidates,
            'recorded_evidence_rows' => $historical,
            'window_may_omit_candidates' => $possible,
            'coverage_state' => !$complete ? 'UNKNOWN' : ($possible ? 'POSSIBLY_TRUNCATED' : 'CANDIDATES_WITHIN_WINDOW'),
            'candidate_counts_may_overlap' => true,
            'exhaustive_open_count' => false,
            'read_only' => true,
            'retry_permitted' => false,
            'external_execution_authorized' => false,
        ];
    }
}
