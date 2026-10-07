<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ListingIntentPackageEvidenceContractTest extends TestCase
{
    public function testCreateIntentFailsClosedWhenDraftPackageEvidenceIsUnavailable(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
        $start=strpos($source,'public function createIntent(');
        $end=strpos($source,'public function transition(',$start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method=substr($source,$start,$end-$start);

        self::assertStringContainsString('findEvidence(Tables::etsy_draft_packages()',$method);
        self::assertStringContainsString("'listing_intent_package_evidence_unavailable'",$method);
        self::assertStringContainsString('if($package instanceof WP_Error)return $package;',$method);
        self::assertStringNotContainsString('$this->find(Tables::etsy_draft_packages()',$method);
    }
}
