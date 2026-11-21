<?php
declare(strict_types=1);
final class FixtureLifecycleTest extends WP_UnitTestCase {
    public function testFixtureLifecycle(): void {
        global $wpdb;
        self::assertSame('digiforge_tests', $wpdb->get_var('SELECT DATABASE()'));
        self::assertSame('1', (string) $wpdb->get_var('SELECT @@GLOBAL.skip_networking'));
        self::assertSame('1', (string) $wpdb->get_var('SELECT 1'));
        self::assertNotSame('assertion-failure', getenv('R1_MODE'));
    }
}
