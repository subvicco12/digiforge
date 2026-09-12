<?php
declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\DigitalFactory\Lifecycle as DigitalLifecycle;
use DigiForge\ProductFactory\Lifecycle as ProductLifecycle;
use PHPUnit\Framework\TestCase;

final class LifecycleTest extends TestCase
{
    public function testProductLifecycleRejectsStateSkipping(): void
    {
        self::assertTrue(ProductLifecycle::can_transition('product', 'DRAFT', 'READY'));
        self::assertFalse(ProductLifecycle::can_transition('product', 'DRAFT', 'ACTIVE'));
        self::assertFalse(ProductLifecycle::can_transition('product_version', 'DRAFT', 'RELEASED'));
    }

    public function testDigitalLifecycleRequiresSequentialApproval(): void
    {
        self::assertTrue(DigitalLifecycle::can_transition('DRAFT', 'FILES_PENDING'));
        self::assertFalse(DigitalLifecycle::can_transition('DRAFT', 'PUBLISH_READY'));
        self::assertFalse(DigitalLifecycle::can_transition('READY_FOR_LISTING', 'PLATFORM_DRAFT'));
    }
}
