<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class F3PodSafetyStructureTest extends TestCase
{
    public function testProviderIntentsRemainBlockedAtCreation(): void
    {
        $repo=file_get_contents(__DIR__.'/../../includes/POD/Repository.php');
        self::assertIsString($repo);
        self::assertStringContainsString("'state' => 'BLOCKED'",$repo);
        self::assertStringContainsString("'PREPARE_ORDER'",$repo);
        self::assertStringNotContainsString("'state' => 'EXECUTED'",$repo);
    }

    public function testPodReadinessRequiresHumanApprovedMappingAndReleaseEvidence(): void
    {
        $repo=file_get_contents(__DIR__.'/../../includes/POD/Repository.php');
        self::assertIsString($repo);
        self::assertStringContainsString("WHERE production_plan_id=%d AND state='RELEASE_READY'",$repo);
        self::assertStringContainsString("(int)($mapping['approved_by'] ?? 0) > 0",$repo);
        self::assertStringContainsString("$areas > 0",$repo);
        self::assertStringContainsString("$personalizationApproved",$repo);
    }

    public function testRestMutationsRequireIdempotencyKeyAndFailClosedOnReplay(): void
    {
        $controller=file_get_contents(__DIR__.'/../../includes/REST/PodController.php');
        self::assertIsString($controller);
        self::assertStringContainsString("get_header('Idempotency-Key')",$controller);
        self::assertStringContainsString('missing_idempotency_key',$controller);
        self::assertStringContainsString('idempotency_conflict',$controller);
        self::assertStringContainsString("hash('sha256',$operation.'|'.$header)",$controller);
    }

    public function testProviderExecutionIsNotImplementedByPodRepository(): void
    {
        $repo=file_get_contents(__DIR__.'/../../includes/POD/Repository.php');
        self::assertIsString($repo);
        self::assertStringNotContainsString('wp_remote_post(',$repo);
        self::assertStringNotContainsString('wp_remote_request(',$repo);
        self::assertStringNotContainsString('curl_exec(',$repo);
    }
}
