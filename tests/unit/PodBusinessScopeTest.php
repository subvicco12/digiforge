<?php

declare(strict_types=1);

use DigiForge\POD\BusinessScope;
use PHPUnit\Framework\TestCase;

final class PodBusinessScopeTest extends TestCase
{
    public function testPersonalizedDigiCraftifyGoodsScopeIsAccepted(): void
    {
        self::assertSame([
            'business_id' => 'digicraftifygoods',
            'store_id' => 'digicraftifygoods',
            'product_program' => BusinessScope::PERSONALIZED_POD,
        ], BusinessScope::normalize([
            'business_id' => 'DigiCraftifyGoods',
            'store_id' => 'DigiCraftifyGoods',
            'product_program' => 'personalized_pod',
        ]));
    }

    public function testOriginalDesignProgramIsRejectedForDigiCraftifyGoods(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BusinessScope::normalize([
            'business_id' => 'DigiCraftifyGoods',
            'store_id' => 'DigiCraftifyGoods',
            'product_program' => BusinessScope::ORIGINAL_DESIGN_POD,
        ]);
    }

    public function testFutureOriginalDesignBusinessCanUseOriginalProgram(): void
    {
        self::assertSame('ORIGINAL_DESIGN_POD', BusinessScope::normalize([
            'business_id' => 'future-original-pod',
            'store_id' => 'future-store',
            'product_program' => BusinessScope::ORIGINAL_DESIGN_POD,
        ])['product_program']);
    }

    /** @dataProvider missingScopeProvider */
    public function testMissingScopeFailsClosed(array $scope): void
    {
        $this->expectException(InvalidArgumentException::class);
        BusinessScope::normalize($scope);
    }

    public static function missingScopeProvider(): array
    {
        return [
            'business' => [['store_id' => 'store', 'product_program' => BusinessScope::PERSONALIZED_POD]],
            'store' => [['business_id' => 'business', 'product_program' => BusinessScope::PERSONALIZED_POD]],
            'program' => [['business_id' => 'business', 'store_id' => 'store']],
            'unsupported program' => [['business_id' => 'business', 'store_id' => 'store', 'product_program' => 'GENERAL_POD']],
        ];
    }

    public function testScopeMismatchFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BusinessScope::assertMatches(
            ['business_id' => 'a', 'store_id' => 'one', 'product_program' => BusinessScope::PERSONALIZED_POD],
            ['business_id' => 'a', 'store_id' => 'two', 'product_program' => BusinessScope::PERSONALIZED_POD]
        );
    }
}
