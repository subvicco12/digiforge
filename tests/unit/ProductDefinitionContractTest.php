<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\ProductFactory\ProductDefinitionContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProductDefinitionContractTest extends TestCase
{
    public function testDefinitionIsNormalizedAndExternalExecutionIsAlwaysFalse(): void
    {
        $value = ProductDefinitionContract::normalize([
            'key' => 'Wedding Welcome Kit',
            'category' => 'Wedding Printables',
            'shop' => 'digital',
            'title' => 'Destination Wedding Welcome Kit',
            'deliverables' => ['A4 PDF', 'US Letter PDF', 'A4 PDF'],
            'qa_requirements' => ['page count', 'customer package isolation'],
            'personalization' => true,
            'external_execution' => true,
        ]);

        self::assertSame('digiforge-product-definition-v1', $value['schema']);
        self::assertSame('weddingwelcomekit', $value['key']);
        self::assertSame(['A4 PDF', 'US Letter PDF'], $value['deliverables']);
        self::assertTrue($value['personalization']);
        self::assertFalse($value['external_execution']);
    }

    public function testDefinitionRejectsUnsupportedShop(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProductDefinitionContract::normalize([
            'key' => 'x',
            'category' => 'x',
            'shop' => 'etsy',
            'title' => 'x',
            'deliverables' => ['file'],
            'qa_requirements' => ['check'],
        ]);
    }

    public function testDefinitionRequiresQaAndDeliverables(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProductDefinitionContract::normalize([
            'key' => 'x',
            'category' => 'x',
            'shop' => 'digital',
            'title' => 'x',
            'deliverables' => [],
            'qa_requirements' => ['check'],
        ]);
    }
}
