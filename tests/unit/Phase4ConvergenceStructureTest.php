<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class Phase4ConvergenceStructureTest extends TestCase
{
    public function testProviderRoutePreparationRequiresBlockedPersistedIntent():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProviderRoutePreparation.php');
        self::assertIsString($s);
        self::assertStringContainsString("!=='BLOCKED'",$s);
        self::assertStringContainsString('ProviderRouter::prepare',$s);
        self::assertStringNotContainsString('wp_remote_',$s);
    }
    public function testPersonalizationRouteUsesPersistedHumanApprovedEvidence():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProviderRoutePreparation.php');
        self::assertIsString($s);
        foreach(['personalization_submission_id','personalization_submissions()',"review_status']??'')!=='APPROVED'",'reviewed_by','reviewed_at','personalization_schema_id','payload_hash','personalization_payload_hash'] as $n)self::assertStringContainsString($n,$s);
        self::assertStringNotContainsString('wp_remote_',$s);
    }
    public function testFulfillmentPreparationRequiresEvidenceFirst():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Orders/FulfillmentRoutePreparation.php');
        self::assertIsString($s);
        self::assertLessThan(strpos($s,'FulfillmentRouter::prepare'),strpos($s,'FulfillmentEvidence::validate'));
        self::assertStringContainsString("'adapter_invoked'=>false",$s);
        self::assertStringContainsString("'external_execution_performed'=>false",$s);
        self::assertStringNotContainsString('wp_remote_',$s);
    }
    public function testFulfillmentPlanDerivesPersonalizationSnapshotFromPersistedApprovals():void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Orders/Repository.php');
        self::assertIsString($s);
        foreach(['personalization_submissions()',"review_status='APPROVED'",'reviewed_by>0','reviewed_at IS NOT NULL',"'payload_hash'=>\$hash","'personalization_snapshot'=>Validator::canonicalJson(\$personalization)"] as $n)self::assertStringContainsString($n,$s);
        self::assertStringNotContainsString('personalization_snapshot]??[]', $s);
    }

}
