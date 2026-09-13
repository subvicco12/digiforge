<?php

declare(strict_types=1);

namespace DigiForge\Tests\Unit;

use DigiForge\Readiness\ReleaseReadiness;
use PHPUnit\Framework\TestCase;

final class ReleaseReadinessTest extends TestCase
{
    /** @return array<string, mixed> */
    private function safeContext(): array
    {
        return [
            'schema_current' => 13,
            'schema_expected' => 13,
            'stop_all' => true,
            'automation_armed' => false,
            'switches' => [
                'stop_all' => true,
                'research' => false,
                'ai' => false,
                'product_development' => false,
                'printify' => false,
                'gelato' => false,
                'etsy_draft' => false,
                'etsy_publish' => false,
                'order_automation' => false,
                'gst_automation' => false,
            ],
            'scheduler_inert' => true,
            'integration_total' => 0,
            'enabled_production_integrations' => 0,
            'retention_safeguard_available' => true,
            'recovery_drill_available' => true,
            'activation_authorized' => false,
            'release_version' => '0.11.0',
        ];
    }

    public function testSafeContextIsReadyForInternalRelease(): void
    {
        $result = ReleaseReadiness::evaluate($this->safeContext());
        self::assertSame('READY_FOR_INTERNAL_RELEASE', $result['status']);
        self::assertFalse($result['external_execution_allowed']);
        self::assertFalse($result['activation_authorized']);
        self::assertSame([], $result['enabled_external_switches']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['evidence_hash']);
    }

    public function testReadinessFailsClosedWhenStopAllIsNotAsserted(): void
    {
        $context = $this->safeContext();
        $context['stop_all'] = false;
        $context['switches']['stop_all'] = false;
        $result = ReleaseReadiness::evaluate($context);
        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertFalse($result['checks']['stop_all_active']);
        self::assertFalse($result['activation_authorized']);
    }

    public function testReadinessFailsWhenProductionIntegrationIsEnabled(): void
    {
        $context = $this->safeContext();
        $context['integration_total'] = 2;
        $context['enabled_production_integrations'] = 1;
        $result = ReleaseReadiness::evaluate($context);
        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertFalse($result['checks']['production_integrations_disabled']);
    }

    public function testCanaryIsDeterministicAndNeverExecutes(): void
    {
        $first = ReleaseReadiness::canary($this->safeContext());
        $second = ReleaseReadiness::canary($this->safeContext());
        self::assertSame($first, $second);
        self::assertSame('DRY_RUN_ONLY', $first['mode']);
        self::assertSame(0, $first['provider_calls_made']);
        self::assertSame(0, $first['jobs_enqueued']);
        self::assertSame(0, $first['settings_changed']);
        foreach ($first['results'] as $state) {
            self::assertSame('BLOCKED_BY_STOP_ALL', $state);
        }
    }

    public function testEmergencyStopDrillIsPassAndNonMutating(): void
    {
        $result = ReleaseReadiness::emergencyStop($this->safeContext());
        self::assertSame('PASS', $result['status']);
        self::assertSame(0, $result['settings_changed']);
        self::assertSame(0, $result['jobs_enqueued']);
        self::assertSame(0, $result['provider_calls_made']);
        self::assertFalse($result['activation_authorized']);
        self::assertTrue($result['checks']['activation_endpoint_absent']);
    }
}
