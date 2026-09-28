<?php
declare(strict_types=1);

final class ProvenanceScopedHistoryTest extends WP_UnitTestCase
{
    public function testRecordedHistoryCountIsExactAndPreviewIsBoundedToAuthorization(): void
    {
        DigiForge\Core\Activator::activate();
        global $wpdb;
        $table=DigiForge\Database\Tables::pod_provenance_integrity_evidence();
        $auth=hash('sha256','scoped-history-fixture');
        $other=hash('sha256','other-history-fixture');
        $now=current_time('mysql',true);
        for ($i=0;$i<25;$i++) {
            self::assertSame(1,$wpdb->insert($table,[
                'correlation_hash'=>hash('sha256','scoped-history-'.$i),
                'authorization_hash'=>$auth,'anomaly_type'=>'BINDING_PACKAGE_MISMATCH',
                'package_id'=>$i+1,'package_hash'=>hash('sha256','package-'.$i),
                'actual_package_hash'=>'','evidence_payload'=>'{}',
                'first_observed_at'=>$now,'last_observed_at'=>$now,
            ]));
        }
        self::assertSame(1,$wpdb->insert($table,[
            'correlation_hash'=>hash('sha256','other-history'),
            'authorization_hash'=>$other,'anomaly_type'=>'LEGACY_UNBOUND',
            'package_id'=>0,'package_hash'=>'','actual_package_hash'=>'',
            'evidence_payload'=>'{}','first_observed_at'=>$now,'last_observed_at'=>$now,
        ]));
        $model=new DigiForge\POD\ProductionProvenanceIntegrityHistoryReadModel();
        $result=$model->byAuthorizationHash($auth);
        self::assertSame('RECORDED_HISTORY',$result['lookup_state']);
        self::assertSame('EXACT_AUTHORIZATION_RECORDED',$result['count_scope']);
        self::assertSame(25,$result['recorded_count']);
        self::assertSame(20,$result['preview_limit']);
        self::assertCount(20,$result['items']);
        self::assertTrue($result['preview_truncated']);
        self::assertSame(hash('sha256','scoped-history-24'),$result['items'][0]['correlation_hash']);
        foreach ($result['items'] as $row) {
            self::assertSame($auth,$row['authorization_hash']);
            self::assertFalse($row['retry_permitted']);
            self::assertFalse($row['external_execution_authorized']);
        }
        $compared=$model->byAuthorizationHash($auth,[
            hash('sha256','scoped-history-24'),hash('sha256','scoped-history-23'),
            hash('sha256','other-history'),
        ]);
        self::assertSame('EXACT_AUTHORIZATION_RECORDED_VS_LIVE',$compared['comparison_scope']);
        self::assertSame(2,$compared['recorded_live_overlap_count']);
        self::assertSame(23,$compared['recorded_without_live_correlation_count']);
        self::assertSame('ALSO_LIVE',$compared['items'][0]['comparison_state']);
        self::assertSame('ALSO_LIVE',$compared['items'][1]['comparison_state']);
        self::assertSame('NOT_IN_LIVE_LOOKUP',$compared['items'][2]['comparison_state']);
        self::assertFalse($compared['retry_permitted']);
        self::assertFalse($compared['external_execution_authorized']);
        $none=$model->byAuthorizationHash(hash('sha256','no-history-fixture'));
        self::assertSame('NO_RECORDED_HISTORY',$none['lookup_state']);
        self::assertSame(0,$none['recorded_count']);
        self::assertSame([],$none['items']);
    }
}
