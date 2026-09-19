<?php
declare(strict_types=1);
use DigiForge\Listings\Lifecycle;
use DigiForge\ProductFactory\Workflow;
use PHPUnit\Framework\TestCase;

final class Phase5BehavioralContractTest extends TestCase
{
    public function testListingLifecycleCannotSkipHumanApproval():void
    {
        self::assertTrue(Lifecycle::can('listing','DRAFT','READY_FOR_REVIEW'));
        self::assertFalse(Lifecycle::can('listing','DRAFT','APPROVED'));
        self::assertTrue(Lifecycle::can('listing','READY_FOR_REVIEW','APPROVED'));
    }
    public function testIntentLifecycleCannotSkipApproval():void
    {
        self::assertTrue(Lifecycle::can('intent','BLOCKED','READY_FOR_REVIEW'));
        self::assertFalse(Lifecycle::can('intent','BLOCKED','APPROVED_INTENT'));
        self::assertTrue(Lifecycle::can('intent','READY_FOR_REVIEW','APPROVED_INTENT'));
    }
    public function testWorkflowStopsAtPublishApprovalWithoutApproval():void
    {
        $facts=['research_approved'=>true,'product_version_created'=>true,'production_plan_created'=>true,'assets_started'=>true,'marketing_assets_started'=>true,'qa_complete'=>true,'qa_passed'=>true,'product_approved'=>true,'listing_started'=>true,'listing_approved'=>true,'etsy_draft_ready'=>true,'publish_approved'=>false,'live'=>false];
        self::assertSame(Workflow::PUBLISH_APPROVAL_REQUIRED,Workflow::project($facts));
    }
}
