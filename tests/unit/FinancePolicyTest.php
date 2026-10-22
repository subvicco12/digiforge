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

    public function testNonFiniteFinancialNumbersAreRejected(): void
    {
        foreach ([INF, -INF, NAN] as $invalid) {
            foreach (['amount', 'positiveRate'] as $method) {
                try {
                    Validator::$method($invalid);
                    self::fail('Non-finite finance value was accepted.');
                } catch (InvalidArgumentException $expected) {
                    self::assertNotSame('', $expected->getMessage());
                }
            }
            try {
                Validator::convert(1.0, $invalid);
                self::fail('Non-finite FX conversion rate was accepted.');
            } catch (InvalidArgumentException $expected) {
                self::assertNotSame('', $expected->getMessage());
            }
        }
    }

    public function testConversionRejectsNonFiniteAmountsAndOverflow(): void
    {
        foreach ([[INF, 1.0], [-INF, 1.0], [NAN, 1.0], [PHP_FLOAT_MAX, 2.0]] as [$amount, $rate]) {
            try {
                Validator::convert($amount, $rate);
                self::fail('Non-finite conversion was accepted.');
            } catch (InvalidArgumentException $expected) {
                self::assertNotSame('', $expected->getMessage());
            }
        }
        self::assertSame(-7.5, Validator::convert(-5.0, 1.5));
    }

    public function testRecursiveCredentialKeysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::structured(['safe' => ['nested' => ['access_token' => 'must-not-be-stored']]]);
    }

    public function testReplayRequiresEveryImmutableFieldAndPreservesTextIdentity(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/Finance/Repository.php';
        $repository = new \DigiForge\Finance\Repository();
        $method = new \ReflectionMethod($repository, 'replayCompatible');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($repository, ['environment' => 'test'], [
            'environment' => 'test', 'canonical_hash' => str_repeat('a', 64),
        ]), 'Missing immutable evidence must not satisfy idempotent replay.');
        self::assertFalse($method->invoke($repository, ['jurisdiction' => '001'], [
            'jurisdiction' => '1',
        ]), 'Textual evidence identifiers must not be compared as numbers.');
        self::assertFalse($method->invoke($repository, ['source_id' => '9007199254740993'], [
            'source_id' => 9007199254740992,
        ]), 'Distinct integer identities must not collapse through floating-point comparison.');
        self::assertTrue($method->invoke($repository, ['amount' => '12.3400', 'source_id' => '7'], [
            'amount' => 12.34, 'source_id' => 7,
        ]), 'Database decimal and integer serialization remains replay compatible.');
    }

    public function testLedgerHashesReconstructDatabaseSerializationAndRejectTampering(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/Finance/Repository.php';
        $repository = new \DigiForge\Finance\Repository();
        $method = new \ReflectionMethod($repository, 'ledgerIntegrityValid');
        $method->setAccessible(true);
        $canonical = [
            'environment' => 'test', 'source_type' => 'order', 'source_id' => 7,
            'entry_type' => 'REVENUE', 'currency' => 'EUR', 'amount' => 12.34,
            'base_currency' => 'USD', 'base_amount' => 13.574,
            'effective_date' => '2026-09-10', 'metadata' => ['source' => ['reference' => 'offline-7']],
        ];
        $row = $canonical + ['canonical_hash' => Validator::hash($canonical)];
        $row['source_id'] = '7';
        $row['amount'] = '12.3400';
        $row['base_amount'] = '13.5740';
        $row['metadata'] = Validator::canonicalJson($canonical['metadata']);
        self::assertTrue($method->invoke($repository, $row));
        foreach (['amount' => '999.0000', 'base_amount' => '1.0000', 'metadata' => '{"source":1}', 'canonical_hash' => ''] as $field => $changed) {
            $tampered = $row;
            $tampered[$field] = $changed;
            self::assertFalse($method->invoke($repository, $tampered));
        }
        $row['metadata'] = '{"source":1e999}';
        self::assertFalse($method->invoke($repository, $row));
    }

    public function testSourceVersionEncodesTheCompleteCanonicalDigestWithinExistingSchema(): void
    {
        $source = ['formula_version' => 'v2', 'environment' => 'test', 'period_start' => '2026-09-01',
            'period_end' => '2026-09-30', 'base_currency' => 'USD', 'ledger' => []];
        $version = Validator::periodSourceVersion($source);
        self::assertSame('v2.6wotkjz2trdp47g75u3lyhibrtkfjqsmroqvrhxpm6qi676ljljq', $version);
        self::assertSame(55, strlen($version));
        self::assertMatchesRegularExpression('/^v2\.[a-z2-7]{52}$/D', $version);
        self::assertSame($version, Validator::periodSourceVersion(array_reverse($source, true)));
        $source['ledger'] = [['id' => '7', 'canonical_hash' => str_repeat('a', 64), 'reconciliation_state' => 'UNRECONCILED']];
        $first = Validator::periodSourceVersion($source);
        self::assertNotSame($version, $first);
        $source['ledger'][0]['id'] = '8';
        self::assertNotSame($first, Validator::periodSourceVersion($source));
        $source['ledger'][0]['id'] = '7';
        $source['ledger'][0]['reconciliation_state'] = 'RECONCILED';
        self::assertNotSame($first, Validator::periodSourceVersion($source));
    }
}
