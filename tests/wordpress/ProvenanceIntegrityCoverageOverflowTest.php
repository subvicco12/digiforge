<?php
declare(strict_types=1);

final class ProvenanceIntegrityCoverageOverflowTest extends WP_UnitTestCase
{
    public function testMoreThanTwoHundredLiveAnomaliesCannotDisappearBehindTheEvidenceLimit(): void
    {
        DigiForge\Core\Activator::activate();
        global $wpdb;
        $table=DigiForge\Database\Tables::pod_execution_nonces();
        $now=current_time('mysql',true);
        $values=[];
        for($i=0;$i<205;$i++){
            $values[]=$wpdb->prepare('(%s,%s,%d,%s)',
                hash('sha256','integrity-overflow-nonce-'.$i),
                hash('sha256','integrity-overflow-auth-'.$i),
                1,$now
            );
        }
        self::assertSame(205,$wpdb->query('INSERT INTO '.$table.' (nonce_hash,authorization_hash,consumed_by,consumed_at) VALUES '.implode(',',$values)));

        $projection=(new DigiForge\POD\ProductionProvenanceIntegrityReadModel())->recent(200);
        self::assertSame(200,$projection['count']);
        self::assertSame('BOUNDED_EVIDENCE_WINDOW',$projection['count_scope']);
        self::assertFalse($projection['exhaustive']);
        self::assertFalse($projection['retry_permitted']);
        self::assertFalse($projection['external_execution_authorized']);

        $coverage=(new DigiForge\POD\ProductionProvenanceIntegrityCoverageReadModel())->inspect(
            $projection['current_count'],$projection['historical_count']
        );
        self::assertGreaterThanOrEqual(205,$coverage['source_candidate_rows']['legacy_unbound']);
        self::assertGreaterThan($projection['current_count'],$coverage['candidate_rows']);
        self::assertSame('POSSIBLY_TRUNCATED',$coverage['coverage_state']);
        self::assertTrue($coverage['window_may_omit_candidates']);
        self::assertFalse($coverage['exhaustive_open_count']);
        self::assertFalse($coverage['retry_permitted']);
        self::assertFalse($coverage['external_execution_authorized']);
    }
}
