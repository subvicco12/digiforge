<?php

declare(strict_types=1);

use DigiForge\POD\PrintifyCatalogContract;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/POD/PrintifyCatalogContract.php';

final class PrintifyCatalogTimestampContractTest extends TestCase
{
    private function variant(string $observedAt): array
    {
        return [
            'blueprint_id' => 101,
            'print_provider_id' => 2,
            'variant_id' => 202,
            'environment' => 'sandbox',
            'attributes' => [],
            'observed_at' => $observedAt,
        ];
    }

    public function testAbsoluteIsoTimestampsWithOffsetOrUtcDesignatorAreAccepted(): void
    {
        self::assertSame(
            '2026-10-11 12:00:00',
            PrintifyCatalogContract::normalizeCatalogVariant($this->variant('2026-10-11T12:00:00+00:00'))['observed_at']
        );
        self::assertSame(
            '2026-10-11 12:00:00',
            PrintifyCatalogContract::normalizeCatalogVariant($this->variant('2026-10-11T12:00:00Z'))['observed_at']
        );
    }

    public function testTimezoneLessObservedAtIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('observed_at must be an absolute ISO-style timestamp with timezone');
        PrintifyCatalogContract::normalizeCatalogVariant($this->variant('2026-10-11T12:00:00'));
    }
}
