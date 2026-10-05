<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class P5LocalIdempotencyTransportContractTest extends TestCase
{
    public function testOrderAndFinanceKeepDurableIdempotencyWithBoundedJsonTransportFallback(): void
    {
        foreach (['OrderController.php','FinanceController.php'] as $file) {
            $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/'.$file);
            self::assertStringContainsString("get_header('Idempotency-Key')",$source);
            self::assertStringContainsString("['idempotency_key']",$source);
            self::assertStringContainsString("strlen($key)>191",$source);
            self::assertStringContainsString("hash_equals($header,$bodyKey)",$source);
            self::assertStringContainsString("new Idempotency()",$source);
            self::assertStringContainsString("->reserve($storage,$operation)",$source);
            self::assertStringContainsString("->complete($storage",$source);
            self::assertStringContainsString("'idempotency_key_mismatch'",$source);
        }
    }
}
