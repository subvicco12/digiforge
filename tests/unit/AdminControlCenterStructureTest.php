<?php

declare(strict_types=1);

namespace {
    if (! defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    if (! function_exists('__')) {
        function __(string $text, string $domain = ''): string
        {
            return $text;
        }
    }

    if (! function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return $text;
        }
    }

    if (! function_exists('esc_html__')) {
        function esc_html__(string $text, string $domain = ''): string
        {
            return $text;
        }
    }

    if (! function_exists('esc_html_e')) {
        function esc_html_e(string $text, string $domain = ''): void
        {
            echo $text;
        }
    }

    if (! function_exists('current_user_can')) {
        function current_user_can(string $capability): bool
        {
            return true;
        }
    }

    if (! function_exists('add_menu_page')) {
        function add_menu_page(
            string $page_title,
            string $menu_title,
            string $capability,
            string $menu_slug,
            callable $callback,
            string $icon_url = '',
            ?int $position = null
        ): bool {
            $GLOBALS['digiforge_test_menu_pages'][] = [
                'page_title' => $page_title,
                'menu_title' => $menu_title,
                'capability' => $capability,
                'menu_slug' => $menu_slug,
                'callback' => $callback,
                'icon_url' => $icon_url,
                'position' => $position,
            ];

            return true;
        }
    }

    if (! function_exists('add_submenu_page')) {
        function add_submenu_page(
            string $parent_slug,
            string $page_title,
            string $menu_title,
            string $capability,
            string $menu_slug,
            callable $callback
        ): bool {
            $GLOBALS['digiforge_test_submenu_pages'][] = [
                'parent_slug' => $parent_slug,
                'page_title' => $page_title,
                'menu_title' => $menu_title,
                'capability' => $capability,
                'menu_slug' => $menu_slug,
                'callback' => $callback,
            ];

            return true;
        }
    }

    if (! function_exists('get_option')) {
        function get_option(string $option, mixed $default = false): mixed
        {
            return $GLOBALS['digiforge_test_options'][$option] ?? $default;
        }
    }

    if (! function_exists('current_time')) {
        function current_time(string $type, bool $gmt = false): string
        {
            return '2026-01-01 00:00:00';
        }
    }

    if (! function_exists('digiforge_test_wpdb_stub')) {
        function digiforge_test_wpdb_stub(): object
        {
            return new class {
                public string $prefix = 'wp_';
                public string $last_error = '';

                public function prepare(string $query, mixed ...$args): string
                {
                    return $query;
                }

                public function get_row(string $query, mixed $output = null): ?array
                {
                    return null;
                }

                public function get_var(string $query): string
                {
                    return '0';
                }
            };
        }
    }

    $GLOBALS['wpdb'] ??= digiforge_test_wpdb_stub();

    require_once __DIR__ . '/../../includes/Database/Tables.php';
    require_once __DIR__ . '/../../includes/Core/Settings.php';
    require_once __DIR__ . '/../../includes/Security/Logger.php';
    require_once __DIR__ . '/../../includes/Observability/HealthMonitor.php';
    require_once __DIR__ . '/../../includes/Operations/Readiness.php';
    require_once __DIR__ . '/../../includes/Core/Admin.php';
}

namespace DigiForge\Tests {
    use DigiForge\Core\Admin;
    use PHPUnit\Framework\TestCase;

    final class AdminControlCenterStructureTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['digiforge_test_menu_pages'] = [];
            $GLOBALS['digiforge_test_submenu_pages'] = [];
            $GLOBALS['digiforge_test_options'] = [];
            $GLOBALS['wpdb'] = \digiforge_test_wpdb_stub();
        }

        public function testMenuRegistersCentralOperationalSurfaces(): void
        {
            $admin = new Admin();

            $admin->menu();

            self::assertCount(1, $GLOBALS['digiforge_test_menu_pages']);
            self::assertSame('digiforge', $GLOBALS['digiforge_test_menu_pages'][0]['menu_slug']);

            $slugs = array_column($GLOBALS['digiforge_test_submenu_pages'], 'menu_slug');

            self::assertContains('digiforge-system-status', $slugs);
            self::assertContains('digiforge-readiness', $slugs);
            self::assertContains('digiforge-safety-controls', $slugs);
            self::assertContains('digiforge-product-factory', $slugs);
            self::assertContains('digiforge-opportunity', $slugs);
            self::assertContains('digiforge-product-family', $slugs);
            self::assertContains('digiforge-product', $slugs);
            self::assertContains('digiforge-product-version', $slugs);
        }

        public function testSafetyControlsScreenRemainsReadOnly(): void
        {
            $admin = new Admin();

            ob_start();
            $admin->render_safety_controls();
            $output = (string) ob_get_clean();

            self::assertStringContainsString('This screen intentionally provides no activation controls.', $output);
            self::assertStringContainsString('Activation authorized', $output);
            self::assertStringContainsString('Automation armed', $output);
            self::assertStringNotContainsString('<form', $output);
            self::assertStringNotContainsString('type="submit"', $output);
            self::assertStringNotContainsString('name="automation_armed"', $output);
            self::assertStringNotContainsString('name="activation_authorized"', $output);
            self::assertStringNotContainsString('name="stop_all"', $output);
        }
    }
}
