<?php

declare(strict_types=1);

use DigiForge\Launch\ResearchActivationPreflight;
use PHPUnit\Framework\TestCase;

final class ResearchActivationPreflightTest extends TestCase
{
    public function test_ready_projection_requires_locked_recovered_connector_state_without_effective_execution(): void
    {
        $report = ResearchActivationPreflight::summarize([
            'readiness_status' => 'READY_LOCKED',
            'externally_locked' => true,
            'recovery_status' => 'PASS',
            'stop_all' => true,
            'activation_authorized' => false,
            'automation_armed' => false,
            'research_configured' => false,
            'ai_configured' => false,
            'research_effective' => false,
            'ai_effective' => false,
            'connector_ready' => true,
            'credential_present' => true,
            'credential_decryptable' => true,
        ]);

        self::assertSame('READY_FOR_CONTROLLED_RESEARCH_ACTIVATION', $report['status']);
        self::assertSame([], $report['blockers']);
        self::assertSame('EXPLICIT_ACTIVATION_AUTHORIZATION_REQUIRED', $report['next_action']);
        self::assertFalse($report['network_requests_performed']);
        self::assertFalse($report['external_actions_performed']);
    }

    public function test_preflight_fails_closed_when_connector_or_recovery_evidence_is_missing(): void
    {
        $report = ResearchActivationPreflight::summarize([
            'readiness_status' => 'READY_LOCKED',
            'externally_locked' => true,
            'recovery_status' => 'REVIEW_REQUIRED',
            'stop_all' => true,
            'activation_authorized' => false,
            'automation_armed' => false,
            'research_effective' => false,
            'ai_effective' => false,
            'connector_ready' => false,
            'credential_present' => false,
            'credential_decryptable' => false,
        ]);

        self::assertSame('BLOCKED', $report['status']);
        self::assertContains('recovery_pass', $report['blockers']);
        self::assertContains('production_ai_connector_ready', $report['blockers']);
        self::assertContains('credential_present', $report['blockers']);
        self::assertContains('credential_decryptable', $report['blockers']);
        self::assertSame('RESOLVE_PREFLIGHT_BLOCKERS', $report['next_action']);
        self::assertFalse($report['external_actions_performed']);
    }

    public function test_preflight_refuses_to_certify_after_any_effective_research_or_ai_execution_gate_is_open(): void
    {
        $report = ResearchActivationPreflight::summarize([
            'readiness_status' => 'READY_LOCKED',
            'externally_locked' => true,
            'recovery_status' => 'PASS',
            'stop_all' => true,
            'activation_authorized' => false,
            'automation_armed' => false,
            'research_effective' => true,
            'ai_effective' => false,
            'connector_ready' => true,
            'credential_present' => true,
            'credential_decryptable' => true,
        ]);

        self::assertSame('BLOCKED', $report['status']);
        self::assertContains('research_not_effective_yet', $report['blockers']);
        self::assertFalse($report['network_requests_performed']);
        self::assertFalse($report['external_actions_performed']);
    }
}
