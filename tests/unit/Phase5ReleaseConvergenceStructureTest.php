<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class Phase5ReleaseConvergenceStructureTest extends TestCase
{
    public function testDraftPreparationCannotPublish():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyDraftPreparation.php');self::assertIsString($s);
        self::assertStringContainsString("'etsy_api_invoked'=>false",$s);self::assertStringContainsString("'external_execution_performed'=>false",$s);self::assertStringNotContainsString('wp_remote_',$s);
    }
    public function testReleaseEvidenceCannotAuthorizePublish():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Listings/ReleaseEvidence.php');self::assertIsString($s);
        self::assertStringContainsString("'publish_authorized'=>false",$s);self::assertStringNotContainsString('wp_remote_',$s);
    }
    public function testWorkflowStillRequiresPublishApproval():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/Workflow.php');self::assertIsString($s);
        self::assertStringContainsString('PUBLISH_APPROVAL_REQUIRED',$s);
    }
}
