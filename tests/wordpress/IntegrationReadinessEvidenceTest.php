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
        self::assertSame(1,$wpdb->insert($table,[
            'provider'=>'printify','environment'=>'test','connection_key'=>$key,
            'display_name'=>'Readiness fixture','status'=>'CONFIGURED','enabled'=>1,
            'config'=>wp_json_encode(['_connection_test'=>[
                'ok'=>true,'checked_at'=>$now,'details'=>['secret'=>'never-expose']],
                'other_secret'=>'never-expose']),
            'created_by'=>0,'created_at'=>$now,'updated_at'=>$now,
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
    }
}
