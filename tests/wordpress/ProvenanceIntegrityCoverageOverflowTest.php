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

        $evidenceTable=DigiForge\Database\Tables::pod_provenance_integrity_evidence();
        $recordedBefore=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$evidenceTable);
        $hiddenAuth=hash('sha256','integrity-overflow-auth-0');
        self::assertNotContains($hiddenAuth,array_column($projection['items'],'authorization_hash'));
        $focused=(new DigiForge\POD\ProductionProvenanceIntegrityFocusReadModel())->byAuthorizationHash($hiddenAuth);
        self::assertSame('LIVE_ANOMALIES',$focused['lookup_state']);
        self::assertSame('LEGACY_UNBOUND',$focused['items'][0]['type']);
        self::assertSame('OPEN',$focused['items'][0]['operator_state']);
        self::assertFalse($focused['items'][0]['retry_permitted']);
        self::assertFalse($focused['items'][0]['external_execution_authorized']);
        self::assertSame($recordedBefore,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$evidenceTable));
        self::assertSame('INVALID_REFERENCE',(new DigiForge\POD\ProductionProvenanceIntegrityFocusReadModel())->byAuthorizationHash('invalid')['lookup_state']);
    }
}
