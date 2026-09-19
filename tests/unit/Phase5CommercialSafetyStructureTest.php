<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class Phase5CommercialSafetyStructureTest extends TestCase
{
    public function testPodReadinessRequiresPositiveCommercialSpread():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ReadinessGate.php');self::assertIsString($s);
        self::assertStringContainsString('Sale price must exceed landed cost before readiness approval.',$s);
        self::assertStringContainsString("'publishing_enabled'=>false",$s);
        self::assertStringContainsString("'order_execution_enabled'=>false",$s);
    }
    public function testCommercialEvidenceCannotExecute():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Listings/CommercialEvidence.php');self::assertIsString($s);
        self::assertStringContainsString("'external_execution_performed'=>false",$s);
        self::assertStringNotContainsString('wp_remote_',$s);
    }
    public function testPrepublishBoundaryCannotAuthorizePublish():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyPrepublishPreparation.php');self::assertIsString($s);
        self::assertStringContainsString("'publish_authorized'=>false",$s);
        self::assertStringContainsString("'etsy_api_invoked'=>false",$s);
        self::assertStringNotContainsString('wp_remote_',$s);
    }
}
