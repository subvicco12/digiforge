<?php

declare(strict_types=1);

final class ControlledExecutionTransactionFailureTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        global $wpdb;
        foreach ([
            DigiForge\Database\Tables::pod_execution_nonces(),
            DigiForge\Database\Tables::pod_execution_receipts(),
            DigiForge\Database\Tables::pod_execution_failures(),
        ] as $table) {
            $wpdb->query('TRUNCATE TABLE '.$table);
        }
    }

    public function testWpErrorBecomesTerminalSanitizedFailureAndReplayIsBlocked(): void
    {
        $approval=[
            'state'=>'HUMAN_APPROVED','decision'=>'APPROVE',
            'publishing_enabled'=>false,'order_execution_enabled'=>false,
            'evidence_hash'=>str_repeat('a',64),
        ];
        $authorization=DigiForge\POD\ExecutionAuthorization::issue(
            $approval,'ETSY_DRAFT_CREATE',7,'transaction_failure_nonce_001',900
        );
        self::assertIsArray($authorization);

        $adapter=new class implements DigiForge\POD\ExecutionAdapter {
            public int $calls=0;
            public function execute(array $permit,array $payload):array|WP_Error {
                $this->calls++;
                return new WP_Error('provider_timeout','SECRET provider message',['token'=>'must-not-persist']);
            }
        };

        $result=DigiForge\POD\ControlledExecutionTransaction::execute(
            $adapter,$authorization,'ETSY_DRAFT_CREATE',str_repeat('a',64),8,time(),['safe'=>'payload']
        );
        self::assertWPError($result);
        self::assertSame('digiforge_transaction_failed',$result->get_error_code());
        self::assertSame(1,$adapter->calls);

        global $wpdb;
        $failure=$wpdb->get_row($wpdb->prepare(
            'SELECT * FROM '.DigiForge\Database\Tables::pod_execution_failures().' WHERE authorization_hash=%s',
            $authorization['authorization_hash']
        ),ARRAY_A);
        self::assertIsArray($failure);
        self::assertSame('adapter_error',$failure['failure_category']);
        self::assertSame('provider_timeout',$failure['failure_code']);
        self::assertStringNotContainsString('SECRET',wp_json_encode($failure));
        self::assertStringNotContainsString('must-not-persist',wp_json_encode($failure));

        $receiptCount=(int)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM '.DigiForge\Database\Tables::pod_execution_receipts().' WHERE authorization_hash=%s',
            $authorization['authorization_hash']
        ));
        self::assertSame(0,$receiptCount);

        $nonceCount=(int)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM '.DigiForge\Database\Tables::pod_execution_nonces().' WHERE authorization_hash=%s',
            $authorization['authorization_hash']
        ));
        self::assertSame(1,$nonceCount);

        $replay=DigiForge\POD\ControlledExecutionTransaction::execute(
            $adapter,$authorization,'ETSY_DRAFT_CREATE',str_repeat('a',64),8,time(),['safe'=>'payload']
        );
        self::assertWPError($replay);
        self::assertSame('digiforge_execution_replay',$replay->get_error_code());
        self::assertSame(1,$adapter->calls);
    }
}
