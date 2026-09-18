<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class F3PodSafetyStructureTest extends TestCase
{
    private function repository(): string
    {
        $repo=file_get_contents(__DIR__.'/../../includes/POD/Repository.php');
        self::assertIsString($repo);
        return $repo;
    }

    public function testProviderIntentsRemainBlockedAtCreation(): void
    {
        $repo=$this->repository();
        self::assertStringContainsString("'state'=>'BLOCKED'",$repo);
        self::assertStringContainsString("'PREPARE_ORDER'",$repo);
        self::assertStringNotContainsString("'state'=>'EXECUTED'",$repo);
    }

    public function testPodReadinessRequiresHumanApprovedMappingReleaseAndEconomics(): void
    {
        $repo=$this->repository();
        self::assertStringContainsString("state='RELEASE_READY'",$repo);
        self::assertStringContainsString("(int)(\$mapping['approved_by']??0)>0",$repo);
        self::assertStringContainsString('$areas>0',$repo);
        self::assertStringContainsString('$personalizationApproved',$repo);
        self::assertStringContainsString("state='APPROVED' ORDER BY COALESCE(observed_at,created_at) DESC",$repo);
        self::assertStringContainsString('$economicEvidence',$repo);
        self::assertStringContainsString("'economic_evidence_approved'=>\$economicEvidence",$repo);
        self::assertStringContainsString("trim((string)(\$cost['observed_at']??''))!==''",$repo);
    }

    public function testRepositoryRejectsChangedIdempotentPayloads(): void
    {
        $repo=$this->repository();
        self::assertStringContainsString('replayCompatible',$repo);
        self::assertStringContainsString('idempotency_payload_conflict',$repo);
        self::assertStringContainsString("['idempotent_replay'=>true]",$repo);
        self::assertStringContainsString('Idempotency key too long.',$repo);
    }

    public function testRestMutationsRequireIdempotencyKeyAndFailClosedOnReplay(): void
    {
        $controller=file_get_contents(__DIR__.'/../../includes/REST/PodController.php');
        self::assertIsString($controller);
        self::assertStringContainsString("get_header('Idempotency-Key')",$controller);
        self::assertStringContainsString('missing_idempotency_key',$controller);
        self::assertStringContainsString('idempotency_conflict',$controller);
        self::assertStringContainsString('idempotency_replay',$controller);
        self::assertStringContainsString("if(\$state==='SUCCESS')",$controller);
        self::assertStringContainsString('already completed successfully',$controller);
        self::assertStringContainsString('already pending',$controller);
        $idempotency=file_get_contents(__DIR__.'/../../includes/Queue/Idempotency.php');
        self::assertIsString($idempotency);
        self::assertStringContainsString('public function status(string $key): ?string',$idempotency);
        self::assertStringContainsString("SELECT status FROM ", $idempotency);
        self::assertStringContainsString("hash('sha256',\$operation.'|'.\$header)",$controller);
    }

    public function testProviderExecutionIsNotImplementedByPodRepository(): void
    {
        $repo=$this->repository();
        self::assertStringNotContainsString('wp_remote_post(',$repo);
        self::assertStringNotContainsString('wp_remote_request(',$repo);
        self::assertStringNotContainsString('curl_exec(',$repo);
    }
}
