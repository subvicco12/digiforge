<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3WorkerTimeoutStructureTest extends TestCase
{
    public function test_u3_worker_extends_scheduler_stale_timeout_without_touching_external_controls(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/ProductFactory/ApprovalAutomation.php');
        self::assertIsString($source);
        self::assertStringContainsString("action_scheduler_timeout_period", $source);
        self::assertStringContainsString('return max($seconds, 900);', $source);
        self::assertStringContainsString("@set_time_limit(0);", $source);
        self::assertStringNotContainsString('etsy_publish', $source);
        self::assertStringNotContainsString('printify', $source);
        self::assertStringNotContainsString('gelato', $source);
        self::assertStringNotContainsString('order_automation', $source);
        self::assertStringNotContainsString('gst_automation', $source);
    }
}
