<?php

declare(strict_types=1);

final class ExecutionReceiptRepositoryTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . DigiForge\Database\Tables::pod_execution_receipts());
    }

    private function receipt(string $receiptHash, string $externalReference = 'provider-ref-1'): array
    {
        return [
            'state' => 'EXECUTION_RECORDED',
            'receipt_hash' => $receiptHash,
            'receipt' => [
                'action' => 'CREATE_PROVIDER_DRAFT',
                'evidence_hash' => str_repeat('a', 64),
                'authorization_hash' => str_repeat('b', 64),
                'authorization_nonce_hash' => str_repeat('c', 64),
                'external_reference' => $externalReference,
                'executed_by' => 7,
                'executed_at' => 1700000000,
            ],
        ];
    }

    public function testExactReplayReturnsPersistedIdenticalResource(): void
    {
        $hash = str_repeat('d', 64);
        $first = DigiForge\POD\ExecutionReceiptRepository::save($this->receipt($hash));
        self::assertIsArray($first);
        $second = DigiForge\POD\ExecutionReceiptRepository::save($this->receipt($hash));
        self::assertIsArray($second);
        self::assertSame((int) $first['id'], (int) $second['id']);
        self::assertSame($hash, $second['receipt_hash']);
    }

    public function testDifferentReplayForSameAuthorizationFails409(): void
    {
        $first = DigiForge\POD\ExecutionReceiptRepository::save($this->receipt(str_repeat('d', 64)));
        self::assertIsArray($first);
        $conflict = DigiForge\POD\ExecutionReceiptRepository::save($this->receipt(str_repeat('e', 64), 'provider-ref-2'));
        self::assertWPError($conflict);
        self::assertSame('digiforge_receipt_conflict', $conflict->get_error_code());
        self::assertSame(409, $conflict->get_error_data()['status']);
    }
}
