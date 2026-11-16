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
        foreach(['(new PersonalizationCurrentness($this->personalizationContext))->forPlan(', 'if(is_wp_error($personalization))return $personalization;', 'personalizationEvidenceHash',"'personalization_snapshot'=>Validator::canonicalJson(['submissions'=>\$personalization", "'human_review_required'=>true", "'external_execution_performed'=>false"] as $n)self::assertStringContainsString($n,$s);
        $current=(string)file_get_contents(dirname(__DIR__,2).'/includes/Orders/PersonalizationCurrentness.php');
        foreach(['Tables::personalization_submissions()', "\$matches[0]['review_status']!=='APPROVED'", "(int)\$row['reviewed_by']<1", "empty(\$row['reviewed_at'])", "'payload_hash'=>\$row['payload_hash']", '$current=$this->forOrder($orderId,$requireEveryLine);if(is_wp_error($current))return $current;'] as $n)self::assertStringContainsString($n,$current);
        // Context injection must retain the shared human-review rules for both direct and locked material.
        self::assertStringContainsString('public function __construct(private ?PersonalizationReadContext $personalizationContext=null)', $s);
        self::assertStringContainsString('if($this->context!==null)return $this->context->forOrder($orderId,$requireEveryLine);', $current);
        self::assertStringContainsString('$entry=self::validateLine($orderId,$line,$rows,$selection,$mappingCounts,$requireEveryLine);', $current);
        self::assertStringContainsString("\$entry=self::validateLine((int)\$order['id'],\$line,\$rows,null,\$counts,\$requireEveryLine);", $current);
        $context=(string)file_get_contents(dirname(__DIR__,2).'/includes/Orders/PersonalizationReadContext.php');
        self::assertStringContainsString('private function __construct(', $context);
        self::assertStringContainsString('PersonalizationCurrentness::projectLegacyMaterial($current,$requireEveryLine)', $context);
        self::assertStringNotContainsString('personalization_snapshot]??[]', $s);
    }

}
