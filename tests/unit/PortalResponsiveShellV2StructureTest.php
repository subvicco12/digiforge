<?php

declare(strict_types=1);

namespace DigiForge\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PortalResponsiveShellV2StructureTest extends TestCase
{
    public function testResponsiveShellAssetsAndCacheBustArePresent(): void
    {
        $plugin = file_get_contents(__DIR__ . '/../../digiforge.php');
        $css = file_get_contents(__DIR__ . '/../../assets/portal-ui.css');
        $js = file_get_contents(__DIR__ . '/../../assets/portal-ui.js');

        self::assertIsString($plugin);
        self::assertIsString($css);
        self::assertIsString($js);

        self::assertStringContainsString("Version: 1.0.44", $plugin);
        self::assertStringContainsString("const DIGIFORGE_VERSION = '1.0.44'", $plugin);
        self::assertStringContainsString("assets/portal-ui.css", $plugin);
        self::assertStringContainsString("assets/portal-ui.js", $plugin);

        self::assertStringContainsString('body.df-portal-active #wpadminbar', $css);
        self::assertStringContainsString('@media (max-width:767px)', $css);
        self::assertStringContainsString('transform:none!important', $css);
        self::assertStringContainsString('.df-mobile-nav-toggle', $css);
        self::assertStringContainsString('.df-mobile-nav-backdrop', $css);
        self::assertStringContainsString('.is-nav-open', $css);
        self::assertStringContainsString('.df-view-dashboard', $css);

        // Mobile navigation must be keyboard/screen-reader safe and use the server-rendered active view.
        self::assertStringContainsString("document.body.classList.add('df-portal-active')", $js);
        self::assertStringContainsString("shell.classList.add('df-js')", $js);
        self::assertStringContainsString("nav.querySelector('a.is-active')", $js);
        self::assertStringContainsString("nav.setAttribute('inert', '')", $js);
        self::assertStringContainsString("nav.setAttribute('aria-hidden'", $js);
        self::assertStringContainsString("aria-expanded", $js);
        self::assertStringContainsString("event.key === 'Escape'", $js);
        self::assertStringContainsString("window.matchMedia('(max-width: 767px)')", $js);
    }
}
