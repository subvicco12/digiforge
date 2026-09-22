<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ListingFactoryStructureTest extends TestCase
{
    private function source(): string
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/Listings/ListingFactory.php');
        self::assertIsString($source);
        return $source;
    }

    public function testFactoryRequiresGate2AndReleaseEvidence(): void
    {
        $source=$this->source();
        self::assertStringContainsString("!== 'APPROVED'",$source);
        self::assertStringContainsString("p.state='APPROVED'",$source);
        self::assertStringContainsString("b.state='RELEASE_READY'",$source);
    }

    public function testFactoryStopsAtGate3WithoutExternalExecution(): void
    {
        $source=$this->source();
        self::assertStringContainsString("transition('listing', \$listingId, 'REVIEW_REQUIRED')",$source);
        self::assertStringContainsString("'workflow_status' => 'LISTING_REVIEW_REQUIRED'",$source);
        self::assertStringContainsString("'listing_approval_required' => true",$source);
        self::assertStringContainsString("'draft_package_created' => false",$source);
        self::assertStringContainsString("'etsy_api_invoked' => false",$source);
        self::assertStringContainsString("'external_actions_performed' => false",$source);
        self::assertStringNotContainsString('wp_remote_', $source);
    }

    public function testFactoryUsesApprovedCapabilitySpecAndDeterministicSeo(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'listing_title_draft'",$source);
        self::assertStringContainsString("'listing_description_draft'",$source);
        self::assertStringContainsString("'seo_keywords'",$source);
        self::assertStringContainsString("array_slice(\$keywords, 0, 13)",$source);
        self::assertStringContainsString("'source' => 'approved_product_capability_spec'",$source);
    }
}
