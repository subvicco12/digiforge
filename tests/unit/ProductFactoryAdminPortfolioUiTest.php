<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class ProductFactoryAdminPortfolioUiTest extends TestCase
{
    public function testAdminPortfolioSurfaceRemainsReadOnlyAndBounded(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Core/Admin.php');
        self::assertStringContainsString("all('product_version', 1, Repository::MAX_PAGE_SIZE)", $source);
        self::assertStringNotContainsString('PortfolioProjection::summarize', $source);
        self::assertStringContainsString('ksort($stateCounts)', $source);
        self::assertStringContainsString('does not infer workflow readiness', $source);
        self::assertStringContainsString('Read-only portfolio visibility.', $source);
        self::assertStringContainsString('External actions performed', $source);
        self::assertStringContainsString('most recent 100 product versions', $source);
        self::assertStringNotContainsString('PortfolioProjection::approve', $source);
        self::assertStringNotContainsString('PortfolioProjection::publish', $source);
    }
}
