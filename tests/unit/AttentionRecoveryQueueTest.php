<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AttentionRecoveryQueueTest extends TestCase
{
    public function test_frontend_has_attention_recovery_view_and_preflight_blocker_visibility(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Portal/Portal.php');
        self::assertIsString($source);
        self::assertStringContainsString("'attention' => ['label' => 'Attention & Recovery'", $source);
        self::assertStringContainsString('private function attention()', $source);
        self::assertStringContainsString('ResearchActivationPreflight', $source);
        self::assertStringContainsString('Open operational alerts', $source);
        self::assertStringContainsString('credential_decryptable', $source);
    }

    public function test_attention_view_does_not_infer_readiness_from_operational_status(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Portal/Portal.php');
        self::assertIsString($source);
        self::assertStringContainsString('without inferring readiness from arbitrary operational status text', $source);
        self::assertStringContainsString("state NOT IN ('RESOLVED','CLOSED')", $source);
    }
}
