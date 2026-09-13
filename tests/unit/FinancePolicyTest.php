<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Finance\Lifecycle;
use DigiForge\Finance\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FinancePolicyTest extends TestCase
{
    public function testFinanceIntentsRemainInert(): void
    {
        self::assertSame(
            ['BLOCKED', 'READY_FOR_REVIEW', 'APPROVED_INTENT', 'REJECTED', 'SUPERSEDED'],
            Lifecycle::INTENT_STATES
        );
        foreach (['QUEUED', 'EXECUTING', 'SUBMITTED', 'SYNCED', 'PAID', 'FILED', 'REFUNDED'] as $forbidden) {
            self::assertNotContains($forbidden, Lifecycle::INTENT_STATES);
        }
        self::assertTrue(Lifecycle::can('intent', 'BLOCKED', 'READY_FOR_REVIEW'));
        self::assertFalse(Lifecycle::can('intent', 'BLOCKED', 'APPROVED_INTENT'));
        self::assertFalse(Lifecycle::can('intent', 'APPROVED_INTENT', 'BLOCKED'));
    }

    public function testFinanceValidatorIsFailClosedAndDeterministic(): void
    {
        self::assertSame('USD', Validator::currency('usd'));
        self::assertSame('production', Validator::environment('PRODUCTION'));
        self::assertSame('2026-09-13', Validator::date('2026-09-13'));
        self::assertSame(123.4568, Validator::amount('123.45678'));
        self::assertSame(7.5, Validator::convert(5.0, 1.5));
        self::assertSame(
            Validator::hash(['b' => 2, 'a' => 1]),
            Validator::hash(['a' => 1, 'b' => 2])
        );
    }

    public function testRecursiveCredentialKeysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::structured(['safe' => ['nested' => ['access_token' => 'must-not-be-stored']]]);
    }
}
