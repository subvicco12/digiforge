<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class StageFAcceptanceMatrixV1094ContractTest extends TestCase
{
    public function testCurrentMatrixBindsCertifiedReleaseAndRuntimeWithoutClaimingRecoveryPass(): void
    {
        $m=(string)file_get_contents(__DIR__.'/../../docs/build-stage-f/STAGE-F-ACCEPTANCE-MATRIX-v1.0.94.md');
        foreach([
            'Stage-F Acceptance Matrix — v1.0.94',
            'e0724771f9e194e127852f999a86eaf3c4fac436',
            'Audit #3668: SUCCESS',
            '#11117403126',
            '557ca2a57a28d39647e4f16689a5f34eef3a0997ecb5ca2c0a7f68b409b1beeb',
            '8833f5ded41cfd4789242557dd72408813b8f53def88131f3909465ebbb68e33',
            '27c565cee27ad0d2bba0820de02d9b864834d15d',
            'production v1.0.94 / schema 23/23 / health OK / STOP ALL ON / externally locked',
            'DEFERRED / NON-BLOCKING FOR THIS CLOSURE',
            'Recovery is not claimed current, certified or passed by this matrix.',
            'UNKNOWN external outcomes require reconciliation before retry',
            'No row may be interpreted as activation authorization',
        ] as $needle) {
            self::assertStringContainsString($needle,$m);
        }
        self::assertStringNotContainsString('recovery drill passed',strtolower($m));
    }

    public function testLegacyMatrixRemainsHistoricalRatherThanBeingRewritten(): void
    {
        self::assertFileExists(__DIR__.'/../../docs/build-stage-f/STAGE-F-ACCEPTANCE-MATRIX-v1.0.93.md');
    }
}
