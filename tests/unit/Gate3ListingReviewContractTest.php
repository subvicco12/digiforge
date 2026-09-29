<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Gate3ListingReviewContractTest extends TestCase
{
    public function testListingFactoryPersistsGate3ReviewBeforeAnyEtsyExecution(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/Listings/ListingFactory.php');
        self::assertStringContainsString('createReadinessReview',$source);
        self::assertStringContainsString("'LISTING_REVIEW_REQUIRED'",$source);
        self::assertStringContainsString("'etsy_api_invoked' => false",$source);
        self::assertStringContainsString("'external_actions_performed' => false",$source);
    }

    public function testRepositoryRequiresPersistedHumanGate3DecisionForApproval(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/Listings/Repository.php');
        self::assertStringContainsString('createReadinessReview',$source);
        self::assertStringContainsString('decideReadinessReview',$source);
        self::assertStringContainsString("'decision'=>'PENDING'",$source);
        self::assertStringContainsString('gate3_review_required',$source);
        self::assertStringContainsString("transition('listing',\$listingId,'APPROVED',true)",\$source);
    }

    public function testRestExposesDecisionEndpointButNoPublishEndpoint(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/REST/ListingController.php');
        self::assertStringContainsString("/listings/reviews/(?P<id>\\d+)/decision",$source);
        self::assertStringContainsString('decideReadinessReview',$source);
        self::assertStringNotContainsString('/publish', $source);
    }
}
