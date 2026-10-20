<?php
declare(strict_types=1);

use DigiForge\Finance\ProvisionalProfitMetrics;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2).'/includes/Finance/ExactDecimal.php';
require_once dirname(__DIR__,2).'/includes/Finance/DeclarationContract.php';
if (is_file(dirname(__DIR__,2).'/includes/Finance/ProvisionalProfitMetrics.php')) require_once dirname(__DIR__,2).'/includes/Finance/ProvisionalProfitMetrics.php';

final class FinanceProvisionalProfitMetricsTest extends TestCase
{
    public function testPreservesExactSignedV2BucketsAndMargin(): void
    {
        $amounts=[];
        foreach (['REVENUE'=>'100.0000','REFUND'=>'10.0000','MARKETPLACE_FEE'=>'3.0000','PAYMENT_FEE'=>'2.0000',
            'COGS'=>'30.0000','SHIPPING'=>'4.0000','ADS'=>'1.0000','AI'=>'2.0000','TAX_COLLECTED'=>'7.0000',
            'TAX_EXPENSE'=>'1.0000','OTHER_INCOME'=>'5.0000','OTHER_EXPENSE'=>'3.0000','ADJUSTMENT'=>'-0.5000'] as $category=>$amount) {
            $amounts[]=['category'=>$category,'base_amount'=>$amount];
        }
        $m=ProvisionalProfitMetrics::fromAmounts($amounts);
        self::assertSame('60.0000',$m['gross_profit']);
        self::assertSame('50.0000',$m['contribution_profit']);
        self::assertSame('48.5000',$m['net_profit']);
        self::assertSame('48.5000',$m['margin_percent']);
        self::assertSame('5.0000',$m['fees']);
        self::assertSame('2.0000',$m['ai']);
        self::assertSame('5.0000',$m['other_expense']);
        self::assertSame('4.5000',$m['other_income']);
        self::assertSame('-0.5000',$m['adjustment']);
    }

    public function testAmountsRemainExactAndZeroRevenueHasUnknownMargin(): void
    {
        $m=ProvisionalProfitMetrics::fromAmounts([['category'=>'REVENUE','base_amount'=>'9999999999.9999'],
            ['category'=>'REVENUE','base_amount'=>'0.0001'],['category'=>'AI','base_amount'=>'0.0001']]);
        self::assertSame('10000000000.0000',$m['gross_revenue']);
        self::assertSame('9999999999.9999',$m['net_profit']);
        self::assertNull(ProvisionalProfitMetrics::fromAmounts([['category'=>'COGS','base_amount'=>'-1.0000']])['margin_percent']);
    }

    /** @dataProvider invalidAmounts */
    public function testRejectsUnknownCategoriesAndNoncanonicalAmounts(array $amounts): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProvisionalProfitMetrics::fromAmounts($amounts);
    }
    public static function invalidAmounts(): array
    {
        return [
            [[['category'=>'UNSPECIFIED','base_amount'=>'1.0000']]],
            [[['category'=>'REVENUE','base_amount'=>'1']]],
            [[['category'=>'REVENUE','base_amount'=>'-0.0000']]],
            [[['category'=>'REVENUE','base_amount'=>1.0]]],
            [[['category'=>'REVENUE','base_amount'=>'1.00001']]],
            [[['category'=>'REVENUE','base_amount'=>'01.0000']]],
            [[['category'=>'REVENUE','base_amount'=>'1.0000','amount'=>'1.0000']]],
            [[1=>['category'=>'REVENUE','base_amount'=>'1.0000']]],
        ];
    }
}
