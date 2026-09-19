<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class Phase5ListingSafetyStructureTest extends TestCase
{
    public function testListingIntentsRemainBlocked():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
        self::assertIsString($s);
        self::assertStringContainsString("'state'=>'BLOCKED'",$s);
        self::assertStringContainsString('Authenticated human reviewer required to create a Gate 3 draft package.',$s);
    }
    public function testListingControllerHasNoEtsyTransport():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/REST/ListingController.php');
        self::assertIsString($s);
        self::assertStringNotContainsString('wp_remote_',$s);
    }
    public function testWorkflowRetainsPublishApprovalGate():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/Workflow.php');
        self::assertIsString($s);
        self::assertStringContainsString('PUBLISH_APPROVAL_REQUIRED',$s);
        self::assertStringContainsString("['publish_approved']",$s);
    }
}
