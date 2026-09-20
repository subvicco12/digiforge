<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3WorkerTimeoutStructureTest extends TestCase
{
    public function test_u3_worker_respects_host_watchdog_without_touching_external_controls(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/ProductFactory/ApprovalAutomation.php');
        self::assertIsString($source);
        self::assertStringContainsString("action_scheduler_timeout_period", $source);
        self::assertStringContainsString("digiforge_u3_build_product_stage", $source);
        self::assertStringContainsString("scheduleStage", $source);
        self::assertStringContainsString("development_poll", $source);
        self::assertStringContainsString('startBackgroundDevelop($brief,8000)', $source);
        self::assertStringNotContainsString('developOnly($candidateId,$shop,$runKey)', $source);
        self::assertStringContainsString('return min(max($seconds, 240), 270);', $source);
        self::assertStringContainsString("@set_time_limit(180);", $source);
        self::assertStringNotContainsString('etsy_publish', $source);
        self::assertStringNotContainsString('printify', $source);
        self::assertStringNotContainsString('gelato', $source);
        self::assertStringNotContainsString('order_automation', $source);
        self::assertStringNotContainsString('gst_automation', $source);
    }
}
