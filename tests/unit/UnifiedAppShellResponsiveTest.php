<?php

declare(strict_types=1);

namespace DigiForge\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UnifiedAppShellResponsiveTest extends TestCase
{
    public function testPortalUsesViewportWidthInsteadOfThemeContentWidth(): void
    {
        $css = file_get_contents(__DIR__ . '/../../assets/portal.css');

        self::assertIsString($css);
        self::assertStringContainsString('calc(100vw - 32px)', $css);
        self::assertStringContainsString('max-width:none!important', $css);
        self::assertStringContainsString('margin:16px 0 24px 50%', $css);
        self::assertStringContainsString('transform:translateX(-50%)', $css);
    }

    public function testResponsiveFoundationDefinesDesktopTabletAndMobileLayouts(): void
    {
        $css = file_get_contents(__DIR__ . '/../../assets/portal.css');

        self::assertIsString($css);
        self::assertStringContainsString('@media(min-width:1440px)', $css);
        self::assertStringContainsString('@media(max-width:1199px)', $css);
        self::assertStringContainsString('@media(max-width:899px)', $css);
        self::assertStringContainsString('@media(max-width:767px)', $css);
        self::assertStringContainsString('@media(max-width:479px)', $css);
        self::assertStringContainsString('grid-template-columns:var(--df-sidebar) minmax(0,1fr)', $css);
        self::assertStringContainsString('grid-template-columns:1fr', $css);
    }

    public function testTouchAndAccessibilityBaselinesArePresent(): void
    {
        $css = file_get_contents(__DIR__ . '/../../assets/portal.css');

        self::assertIsString($css);
        self::assertStringContainsString('min-height:44px', $css);
        self::assertStringContainsString(':focus-visible', $css);
        self::assertStringContainsString('@media(prefers-reduced-motion:reduce)', $css);
        self::assertStringContainsString('overflow-x:auto', $css);
    }
}
