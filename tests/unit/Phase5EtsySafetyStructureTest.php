<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class Phase5EtsySafetyStructureTest extends TestCase
{
    public function testEtsyIntentsRemainBlockedAtCreation():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
        self::assertIsString($s);
        self::assertStringContainsString("'state'=>'BLOCKED'",$s);
    }
    public function testEtsyOAuthDoesNotPublishListings():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Integrations/EtsyOAuth.php');
        self::assertIsString($s);
        self::assertStringNotContainsString('/listings',$s);
        self::assertStringNotContainsString('createDraftListing',$s);
    }
    public function testOrderAdminDeclaresExternalActionsDisabled():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Orders/Admin.php');
        self::assertIsString($s);
        self::assertStringContainsString('webhook processing, fulfillment submission, refunds, cancellations and shipping mutations remain disabled',$s);
    }
}
