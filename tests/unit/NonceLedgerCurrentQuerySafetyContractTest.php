<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class NonceLedgerCurrentQuerySafetyContractTest extends TestCase
{
    public function testEveryNonceEvidenceReadClearsStaleDatabaseErrorState(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/POD/ExecutionNonceLedger.php');
        self::assertSame(3,substr_count($source,'$wpdb->flush();'));
        self::assertStringContainsString('$wpdb->flush();$existing=$wpdb->get_var(',$source);
        self::assertStringContainsString('$wpdb->flush();$winner=$wpdb->get_var(',$source);
        self::assertStringContainsString('$wpdb->flush();$found=$wpdb->get_var(',$source);
    }

    public function testAmbiguousNonceInsertNeverAuthorizesAutomaticRetry(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/POD/ExecutionNonceLedger.php');
        self::assertStringContainsString('$wpdb->last_error=\'\';$ok=$wpdb->insert(',$source);
        self::assertStringContainsString('if($ok!==1){',$source);
        self::assertStringContainsString("'digiforge_nonce_store','Execution nonce consumption is unconfirmed; do not retry automatically.'",$source);
        self::assertStringContainsString("'retry_permitted'=>false,'external_execution_authorized'=>false",$source);
    }
}
