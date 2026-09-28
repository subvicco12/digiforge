<?php
declare(strict_types=1);

final class ProvenanceTypeBreakdownTest extends WP_UnitTestCase
{
    public function testExactScopedTypesIncludeUnexpectedRecordedCategories(): void
    {
        DigiForge\Core\Activator::activate();
        global $wpdb;
        $table=DigiForge\Database\Tables::pod_provenance_integrity_evidence();
        $auth=hash('sha256','type-breakdown-auth');
        $types=['BINDING_PACKAGE_MISMATCH','CLOSURE_PACKAGE_MISMATCH',
            'BINDING_CLOSURE_MISMATCH','LEGACY_UNBOUND','FUTURE_TYPE'];
        $now=current_time('mysql',true);
        foreach ($types as $i=>$type) {
            self::assertSame(1,$wpdb->insert($table,[
                'correlation_hash'=>hash('sha256','type-breakdown-'.$i),
                'authorization_hash'=>$auth,'anomaly_type'=>$type,
                'package_id'=>0,'package_hash'=>'','actual_package_hash'=>'',
                'evidence_payload'=>'{}','first_observed_at'=>$now,'last_observed_at'=>$now,
            ]));
        }
        self::assertSame(1,$wpdb->insert($table,[
            'correlation_hash'=>hash('sha256','type-breakdown-unrelated'),
            'authorization_hash'=>hash('sha256','type-breakdown-other'),
            'anomaly_type'=>'LEGACY_UNBOUND',
            'package_id'=>0,'package_hash'=>'','actual_package_hash'=>'',
            'evidence_payload'=>'{}','first_observed_at'=>$now,'last_observed_at'=>$now,
        ]));
        $result=(new DigiForge\POD\ProductionProvenanceIntegrityTypeReadModel())->byAuthorizationHash($auth);
        self::assertSame('RECORDED_TYPES',$result['lookup_state']);
        self::assertSame('EXACT_AUTHORIZATION_RECORDED',$result['count_scope']);
        self::assertSame(5,$result['recorded_count']);
        foreach ($types as $type) {
            if ($type!=='FUTURE_TYPE') self::assertSame(1,$result['type_counts'][$type]);
        }
        self::assertSame(1,$result['type_counts']['OTHER_RECORDED_TYPE']);
        self::assertSame(5,array_sum($result['type_counts']));
        self::assertFalse($result['retry_permitted']);
        self::assertFalse($result['external_execution_authorized']);
    }
}
