<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class F4OrdersFulfillmentSafetyStructureTest extends TestCase
{
    private function repository(): string
    {
        $repo=file_get_contents(__DIR__.'/../../includes/Orders/Repository.php');
        self::assertIsString($repo);
        return $repo;
    }

    public function testRepositoryRejectsChangedIdempotentPayloads(): void
    {
        $repo=$this->repository();
        self::assertStringContainsString('replayCompatible',$repo);
        self::assertStringContainsString('idempotency_payload_conflict',$repo);
        self::assertStringContainsString("['idempotent_replay'=>true]",$repo);
        self::assertStringContainsString('Idempotency key is too long.',$repo);
    }

    public function testPersonalizationRequiresExplicitHumanApproval(): void
    {
        $repo=$this->repository();
        self::assertStringContainsString("'review_status'=>'UNREVIEWED'",$repo);
        self::assertStringContainsString("['APPROVED','REJECTED']",$repo);
        self::assertStringContainsString('Authenticated human reviewer required.',$repo);
        self::assertStringContainsString("ps.review_status='APPROVED'",$repo);
        self::assertStringContainsString('ps.reviewed_by>0',$repo);
        self::assertStringContainsString('ps.reviewed_at IS NOT NULL',$repo);
    }

    public function testPlanCreationAndApprovalRequireCurrentReadiness(): void
    {
        $repo=$this->repository();
        self::assertStringContainsString("empty($readiness['ready'])",$repo);
        self::assertStringContainsString('Order must pass current readiness before a fulfillment plan can be created.',$repo);
        self::assertStringContainsString("$entity==='plan'&&$to==='APPROVED'",$repo);
        self::assertStringContainsString('Fulfillment plan readiness is stale; rebuild the plan before approval.',$repo);
    }

    public function testFulfillmentIntentRequiresSameOrderApprovedCurrentPlanAndStartsBlocked(): void
    {
        $repo=$this->repository();
        self::assertStringContainsString("(int)$plan['order_id']!==(int)$order['id']",$repo);
        self::assertStringContainsString("(string)$plan['state']!=='APPROVED'",$repo);
        self::assertStringContainsString("hash_equals((string)$plan['readiness_hash'],(string)($current['hash']??''))",$repo);
        self::assertStringContainsString("'state'=>'BLOCKED'",$repo);
    }

    public function testOrdersRepositoryDoesNotExecuteProviderNetworkCalls(): void
    {
        $repo=$this->repository();
        self::assertStringNotContainsString('wp_remote_post(',$repo);
        self::assertStringNotContainsString('wp_remote_request(',$repo);
        self::assertStringNotContainsString('curl_exec(',$repo);
    }
}
