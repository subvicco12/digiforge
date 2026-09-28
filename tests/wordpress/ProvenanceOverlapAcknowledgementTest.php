<?php
declare(strict_types=1);

final class ProvenanceOverlapAcknowledgementTest extends WP_UnitTestCase
{
    public function testDistinctOverlappingAnomaliesRetainSeparateAcknowledgementState(): void
    {
        DigiForge\Core\Activator::activate();
        $reviewer=self::factory()->user->create(['role'=>'administrator']);
        wp_set_current_user($reviewer);
        global $wpdb;
        $auth=hash('sha256','integrity-overlap-authorization');
        $nonce=hash('sha256','integrity-overlap-nonce');
        $now=current_time('mysql',true);
        $firstPackage=(int)$wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM '.DigiForge\Database\Tables::pod_authorization_packages())+10001;
        $bindingHash=hash('sha256','integrity-overlap-binding');
        $closureHash=hash('sha256','integrity-overlap-closure-package');
        self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::pod_authorization_bindings(),[
            'package_id'=>$firstPackage,'package_hash'=>$bindingHash,'authorization_hash'=>$auth,
            'nonce_hash'=>$nonce,'bound_by'=>$reviewer,'bound_at'=>$now,
        ]));
        self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::pod_lifecycle_closures(),[
            'package_id'=>$firstPackage+1,'package_hash'=>$closureHash,'authorization_hash'=>$auth,
            'outcome_state'=>'EXECUTION_FAILED','closure_hash'=>hash('sha256','integrity-overlap-closure'),
            'closed_by'=>$reviewer,'external_execution_performed'=>0,
            'external_execution_state'=>'CONFIRMED_FAILURE','created_at'=>$now,
        ]));

        $focused=(new DigiForge\POD\ProductionProvenanceIntegrityFocusReadModel())->byAuthorizationHash($auth);
        self::assertSame('LIVE_ANOMALIES',$focused['lookup_state']);
        self::assertSameCanonicalizing([
            'BINDING_PACKAGE_MISMATCH','CLOSURE_PACKAGE_MISMATCH','BINDING_CLOSURE_MISMATCH',
        ],array_column($focused['items'],'type'));
        self::assertCount(3,array_unique(array_column($focused['items'],'correlation_hash')));
        self::assertSame(['OPEN','OPEN','OPEN'],array_column($focused['items'],'operator_state'));
        self::assertSame(3,$focused['open_count']);
        self::assertSame(0,$focused['acknowledged_count']);
        self::assertSame('EXACT_AUTHORIZATION_LIVE',$focused['count_scope']);

        $recent=(new DigiForge\POD\ProductionProvenanceIntegrityReadModel())->recent(200);
        $matching=array_values(array_filter($recent['items'],static fn(array $item):bool=>$item['authorization_hash']===$auth));
        self::assertCount(3,$matching);
        $coverage=(new DigiForge\POD\ProductionProvenanceIntegrityCoverageReadModel())->inspect($recent['current_count'],$recent['historical_count']);
        foreach (['binding_package_mismatch','closure_package_mismatch','binding_closure_mismatch'] as $type) {
            self::assertGreaterThanOrEqual(1,$coverage['source_candidate_rows'][$type]);
        }
        self::assertFalse($coverage['exhaustive_open_count']);

        $chosen=$focused['items'][0];
        $ack=DigiForge\POD\ProductionProvenanceIntegrityAcknowledgementRepository::acknowledge(
            $chosen['correlation_hash'],$auth,$chosen['type'],$reviewer
        );
        self::assertFalse(is_wp_error($ack));
        self::assertFalse($ack['retry_permitted']);
        self::assertFalse($ack['external_execution_authorized']);
        $after=(new DigiForge\POD\ProductionProvenanceIntegrityFocusReadModel())->byAuthorizationHash($auth);
        self::assertCount(1,array_filter($after['items'],static fn(array $item):bool=>$item['operator_state']==='ACKNOWLEDGED'));
        self::assertCount(2,array_filter($after['items'],static fn(array $item):bool=>$item['operator_state']==='OPEN'));
        self::assertSame(2,$after['open_count']);
        self::assertSame(1,$after['acknowledged_count']);
        foreach ($after['items'] as $item) {
            self::assertFalse($item['retry_permitted']);
            self::assertFalse($item['external_execution_authorized']);
        }
    }
}
