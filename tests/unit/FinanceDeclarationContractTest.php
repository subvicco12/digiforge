<?php
declare(strict_types=1);

use DigiForge\Finance\DeclarationContract;
use DigiForge\Finance\ExactDecimal;
use DigiForge\Tests\FinanceDeclarationFixtures;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FinanceDeclarationFixtures.php';
require_once dirname(__DIR__, 2) . '/includes/Finance/ExactDecimal.php';
require_once dirname(__DIR__, 2) . '/includes/Finance/DeclarationContract.php';

final class FinanceDeclarationContractTest extends TestCase
{
    public function testExactMoneyAndFxNeverUseBinaryFloatArithmetic(): void
    {
        self::assertSame('10000000000.0000', ExactDecimal::add('9999999999.9999', '0.0001'));
        self::assertSame('0.0000', ExactDecimal::add('9999999999.9999', '-9999999999.9999'));
        self::assertSame('0.0001', ExactDecimal::convert('0.0001', '0.5000000000'));
        self::assertSame('-0.0001', ExactDecimal::convert('-0.0001', '0.5000000000'));
        self::assertSame('1.0000', ExactDecimal::convert('9999999999.9999', '0.0000000001'));
        self::assertSame('12.3456', ExactDecimal::money('12.3456'));
    }

    public function testExactMarginUsesExistingV2FormulaAndZeroRevenueRemainsUnknown():void
    {
        self::assertSame('33.3333',ExactDecimal::percentage('1.0000','3.0000'));
        self::assertSame('-33.3333',ExactDecimal::percentage('-1.0000','3.0000'));
        self::assertSame('50.0000',ExactDecimal::percentage('9999999999.9999','19999999999.9998'));
        self::assertSame('0.0001',ExactDecimal::percentage('0.0001','200.0000'));
        self::assertNull(ExactDecimal::percentage('1.0000','0.0000'));
    }

    /** @dataProvider invalidMoney */
    public function testUntypedOrInexactMoneyIsRejected(mixed $money): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExactDecimal::money($money);
    }

    public static function invalidMoney(): array
    {
        return [[0.1], [INF], ['1e2'], ['1.00001'], [' 1.0000'], ['10000000000.0000'], [true], ['01.0000']];
    }

    public function testNormalizedDeclarationsAreTypedHashBoundAndNeverAuthenticateProviders(): void
    {
        $input = FinanceDeclarationFixtures::input();
        $declaration = DeclarationContract::normalize($input);
        self::assertSame('10.0000', $declaration['amount']);
        self::assertSame('UNKNOWN', $declaration['authenticity']);
        self::assertSame('signed-bucket-v1', $declaration['accounting_convention']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $declaration['declaration_hash']);
        self::assertSame($declaration, DeclarationContract::fromStored($declaration));
        $declaration['amount'] = '999.0000';
        $this->expectException(InvalidArgumentException::class);
        DeclarationContract::fromStored($declaration);
    }

    /** @dataProvider malformedDeclarations */
    public function testMalformedDeclarationsCannotBecomeImportEvidence(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        DeclarationContract::normalize($input);
    }

    public static function malformedDeclarations(): array
    {
        $missing = FinanceDeclarationFixtures::input();
        unset($missing['scope']['store_id']);
        return [[$missing], [FinanceDeclarationFixtures::input(['amount' => 10.0])],
            [FinanceDeclarationFixtures::input(['occurred_at' => '2026-02-30T12:00:00Z'])],
            [FinanceDeclarationFixtures::input(['occurred_at' => "2026-10-10T12:00:00Z\0"])],
            [FinanceDeclarationFixtures::input(['currency' => 'usd'])],
            [FinanceDeclarationFixtures::input(['source' => ['kind' => 'AUTHENTICATED_PROVIDER']])],
            [FinanceDeclarationFixtures::input(['revision' => 2])],
            [FinanceDeclarationFixtures::input(['access_token' => 'forbidden'])],
            [FinanceDeclarationFixtures::input(['observed_at' => '2026-10-09T12:00:00Z'])]];
    }
}
