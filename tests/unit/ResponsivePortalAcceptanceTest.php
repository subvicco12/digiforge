<?php

declare(strict_types=1);

namespace DigiForge\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ResponsivePortalAcceptanceTest extends TestCase
{
    public function testResponsiveShellAvoidsThemeWidthAndMobileSidebarTrap(): void
    {
        $css = file_get_contents(__DIR__ . '/../../assets/portal.css');
        self::assertIsString($css);
        self::assertStringContainsString('width:100vw', $css);
        self::assertStringContainsString('margin-left:calc(50% - 50vw)', $css);
        self::assertStringContainsString('@media(max-width:899px)', $css);
        self::assertStringContainsString('flex-direction:row', $css);
        self::assertStringContainsString('overflow-x:auto', $css);
        self::assertStringContainsString('.admin-bar:has(.df-portal-shell) #wpadminbar{display:none!important}', $css);
        self::assertStringContainsString('-webkit-line-clamp:3', $css);
    }
}
