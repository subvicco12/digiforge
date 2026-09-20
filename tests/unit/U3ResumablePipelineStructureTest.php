<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3ResumablePipelineStructureTest extends TestCase
{
    public function test_long_manifest_generation_is_background_polled_and_resumable(): void
    {
        $automation=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/ApprovalAutomation.php');
        $client=(string)file_get_contents(dirname(__DIR__,2).'/includes/Launch/OpenAIClient.php');
        $orchestrator=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/Orchestrator.php');
        self::assertStringContainsString("'manifest_poll'",$automation);
        self::assertStringContainsString("'finalize'",$automation);
        self::assertStringContainsString('preflightManifest',$automation);
        self::assertStringContainsString('manifestRepairBrief',$automation);
        self::assertStringContainsString("'manifest_repair_attempts'",$automation);
        self::assertStringContainsString('u3_product_manifest_auto_repair_scheduled',$automation);
        self::assertStringContainsString('startBackgroundDevelop',$automation);
        self::assertStringContainsString('retrieveBackground',$automation);
        self::assertStringContainsString("'background'=>true",$client);
        self::assertSame(2,substr_count($client,'wp_remote_post'));
        self::assertSame(1,substr_count($client,'wp_remote_get'));
        self::assertStringContainsString('public function developOnly',$orchestrator);
        self::assertStringContainsString("'_manifest_ai'",$orchestrator);
        self::assertStringContainsString("'external_actions'=>false",$automation);
        self::assertStringContainsString('as_schedule_single_action($when,self::STAGE_HOOK,$args,\'digiforge\',false)',$automation);
    }
}
