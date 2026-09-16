<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class F4OrderFulfillmentHardeningStructureTest extends TestCase
{
    private function repo(): string { $v=file_get_contents(__DIR__.'/../../includes/Orders/Repository.php'); self::assertIsString($v); return $v; }
    private function controller(): string { $v=file_get_contents(__DIR__.'/../../includes/REST/OrderController.php'); self::assertIsString($v); return $v; }

    public function testRepositoryReplayRejectsChangedImmutablePayload(): void
    {
        $repo=$this->repo();
        self::assertStringContainsString('idempotency_payload_conflict',$repo);
        self::assertStringContainsString('replayCompatible',$repo);
        self::assertStringContainsString("['idempotent_replay'=>true]",$repo);
    }

    public function testPersonalizationMustBeHumanReviewed(): void
    {
        $repo=$this->repo();$controller=$this->controller();
        self::assertStringContainsString("'review_status'=>'UNREVIEWED'",$repo);
        self::assertStringContainsString("['APPROVED','REJECTED']",$repo);
        self::assertStringContainsString("ps.review_status='APPROVED'",$repo);
        self::assertStringContainsString('reviewed_by>0',$repo);
        self::assertStringContainsString('/orders/personalizations/(?P<id>\\d+)/review',$controller);
    }

    public function testFulfillmentPlanRequiresCurrentReadyOrder(): void
    {
        $repo=$this->repo();
        self::assertStringContainsString('Order must pass current readiness before a fulfillment plan can be created.',$repo);
        self::assertStringContainsString('Fulfillment plan readiness is stale; rebuild the plan before approval.',$repo);
        self::assertStringContainsString("hash_equals((string)\$row['readiness_hash']",$repo);
    }

    public function testIntentRequiresApprovedSameOrderPlanAndStaysBlocked(): void
    {
        $repo=$this->repo();
        self::assertStringContainsString('Intent requires an approved fulfillment plan for the same order and environment.',$repo);
        self::assertStringContainsString('Fulfillment plan readiness is stale; rebuild and reapprove the plan.',$repo);
        self::assertStringContainsString("'state'=>'BLOCKED'",$repo);
    }

    public function testSameStateTransitionIsIdempotent(): void
    {
        $repo=$this->repo();
        self::assertStringContainsString("['idempotent_transition'=>true]",$repo);
    }
}
