<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductLineTabsTest extends TestCase
{
    public function test_frontend_console_has_distinct_digital_personalized_and_future_non_personalized_pod_views(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Portal/Portal.php');
        self::assertIsString($source);
        self::assertStringContainsString("'digital' => ['label' => 'Digital Products'", $source);
        self::assertStringContainsString("'pod_personalized' => ['label' => 'POD — Personalized'", $source);
        self::assertStringContainsString("'pod_future_nonpersonalized' => ['label' => 'POD — Future Non-Personalized'", $source);
        self::assertStringContainsString('futureNonPersonalizedPod()', $source);
        self::assertStringContainsString('PLANNED · INERT', $source);
        self::assertStringContainsString('NOT IMPLEMENTED', $source);
    }

    public function test_future_non_personalized_lane_does_not_claim_an_authoritative_personalization_classification(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Portal/Portal.php');
        self::assertIsString($source);
        self::assertStringContainsString('does not infer personalization classification', $source);
        self::assertStringContainsString('No non-personalized POD execution', $source);
    }
}
