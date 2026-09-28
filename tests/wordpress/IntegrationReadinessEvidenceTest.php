<?php
declare(strict_types=1);

final class IntegrationReadinessEvidenceTest extends WP_UnitTestCase
{
    public function testConfiguredStatusUsesStoredTestMetadataWithoutExposingConfig(): void
    {
        DigiForge\Core\Activator::activate();
        global $wpdb;
        $table=DigiForge\Database\Tables::integrations();
        $now=current_time('mysql',true);
        $key='integration_readiness_fixture';
        $fingerprint='abcdef0123456789';
        $version=DigiForge\Integrations\CredentialEvidenceVersion::fromMetadata([
            ['secret_name'=>'api_key','fingerprint'=>$fingerprint],
        ]);
        self::assertSame(1,$wpdb->insert($table,[
            'provider'=>'printify','environment'=>'test','connection_key'=>$key,
            'display_name'=>'Readiness fixture','status'=>'CONFIGURED','enabled'=>1,
            'config'=>wp_json_encode(['_connection_test'=>[
                'ok'=>true,'checked_at'=>$now,'credential_evidence_version'=>$version,
                'details'=>['secret'=>'never-expose']],
                'other_secret'=>'never-expose']),
            'created_by'=>0,'created_at'=>$now,'updated_at'=>$now,
        ]));
        $id=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::integration_secrets(),[
            'integration_id'=>$id,'secret_name'=>'api_key','ciphertext'=>'fixture-ciphertext',
            'fingerprint'=>$fingerprint,'created_at'=>$now,'updated_at'=>$now,
        ]));
        $snapshot=(new DigiForge\Portal\IntegrationReadinessReadModel())->snapshot();
        self::assertSame('AVAILABLE',$snapshot['query_state']);
        $matched=array_values(array_filter($snapshot['items'],static fn(array $row):bool=>
            (string)($row['display_name']??'')==='Readiness fixture'));
        self::assertCount(1,$matched);
        self::assertSame('TEST_SUCCESS_RECORDED',$matched[0]['evidence_state']);
        self::assertSame($now,$matched[0]['last_stored_test_at']);
        self::assertArrayNotHasKey('config',$matched[0]);
        self::assertArrayNotHasKey('connection_key',$matched[0]);
        self::assertStringNotContainsString('never-expose',wp_json_encode($snapshot));
        self::assertFalse($snapshot['connectivity_test_performed']);
        self::assertFalse($snapshot['external_execution_authorized']);
        self::assertSame(1,$wpdb->update(DigiForge\Database\Tables::integration_secrets(),
            ['fingerprint'=>'fedcba9876543210'],['integration_id'=>$id,'secret_name'=>'api_key']));
        $after=(new DigiForge\Portal\IntegrationReadinessReadModel())->snapshot();
        $stale=array_values(array_filter($after['items'],static fn(array $row):bool=>
            (string)($row['display_name']??'')==='Readiness fixture'));
        self::assertSame('CONFIGURED_UNVERIFIED',$stale[0]['evidence_state']);
        self::assertNull($stale[0]['last_stored_test_at']);
        self::assertFalse($stale[0]['external_execution_authorized']);
    }
}
