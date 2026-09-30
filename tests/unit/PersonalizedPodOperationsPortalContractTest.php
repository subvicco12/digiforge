<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PersonalizedPodOperationsPortalContractTest extends TestCase
{
    public function testReadModelIsBoundedFailClosedAndNonExecuting(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/POD/PersonalizedPodOperationsReadModel.php');
        foreach([
            'ProductionPreflightOperatorReadModel',
            "'query_state'=>'UNAVAILABLE'",
            "'external_execution_authorized'=>false",
            "'external_execution_performed'=>false",
            "'retry_permitted'=>false",
            'PREFLIGHT_EVIDENCE_UNAVAILABLE',
        ] as $needle) self::assertStringContainsString($needle,$source);
        self::assertStringNotContainsString('wp_remote_',$source);
        self::assertStringNotContainsString('ProductionExecutionPermit',$source);
        $render=(string)file_get_contents(__DIR__.'/../../includes/POD/RenderEvidenceOperationsReadModel.php');
        self::assertStringContainsString("'query_state'=>'UNAVAILABLE'",$render);
        self::assertStringContainsString("'external_execution_authorized'=>false",$render);
        self::assertStringNotContainsString('wp_remote_',$render);
    }

    public function testPortalExposesPhase1StateWithoutProviderAction(): void
    {
        $source=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
        foreach([
            'Phase-1 Personalized POD operations',
            'PHASE 1 · EXTERNALLY LOCKED',
            'Human review required',
            'Preflight current',
            'Revalidation required',
            'Provider execution authority',
            'PHASE 1 · OPERATIONAL / EXTERNALLY LOCKED',
        ] as $needle) self::assertStringContainsString($needle,$source);
        self::assertStringContainsString('It does not authorize production',$source);
        self::assertStringContainsString('Record human package review',$source);
        self::assertStringContainsString("guard('manage_digiforge_pod')",$source);
        self::assertStringContainsString('ProductionAuthorizationRepository',$source);
        self::assertStringContainsString('Production remains externally locked',$source);
        self::assertStringContainsString('Buyer-specific artwork & mockup review',$source);
        self::assertStringContainsString('Approve visual render',$source);
        self::assertStringContainsString('RenderEvidenceRepository',$source);
    }
}
