<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3SelfHealingRuntimeTest extends TestCase
{
    public function test_runtime_self_heals_without_external_actions(): void
    {
        $automation=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/ApprovalAutomation.php');
        self::assertStringContainsString('MAX_STAGE_RETRIES = 3',$automation);
        self::assertStringContainsString('MAX_MANIFEST_REPAIRS = 2',$automation);
        self::assertStringContainsString('MAX_QA_REPAIRS = 2',$automation);
        self::assertStringContainsString('as_enqueue_async_action(self::STAGE_HOOK',$automation);
        self::assertStringContainsString("'digiforge',false",$automation);
        self::assertStringContainsString('u3_product_build_auto_retry',$automation);
        self::assertStringContainsString('u3_product_build_auto_repair_scheduled',$automation);
        self::assertStringContainsString('pauseAndSchedule',$automation);
        self::assertStringContainsString('u3_run_active',$automation);
        self::assertStringContainsString('MAX_RUNTIME_SECONDS = 1200',$automation);
        self::assertStringNotContainsString('etsy_publish',$automation);
        self::assertStringNotContainsString('printify',$automation);
        self::assertStringNotContainsString('gelato',$automation);
    }

    public function test_digital_spec_is_capability_normalized_before_persistence(): void
    {
        $engine=(string)file_get_contents(dirname(__DIR__,2).'/includes/Launch/ExecutionEngine.php');
        self::assertStringContainsString('normalizeDigitalSpecification',$engine);
        self::assertStringContainsString("'delivery_selection'",$engine);
        self::assertStringContainsString("'deferred_variants'",$engine);
        self::assertStringContainsString("'expected_page_counts'",$engine);
        self::assertStringContainsString('requires_language_review',$engine);
        self::assertStringContainsString('reviewed English-language files only',$engine);
        self::assertStringContainsString("\$requirements['package_structure']=\$selectedFiles",str_replace(' ','',$engine));
        self::assertStringContainsString('$pageCounts=[]',$engine);
        self::assertStringContainsString('if($translationVariants===[])return $spec',$engine);
        self::assertStringContainsString("'machine_evidence_files'",$engine);
        self::assertStringContainsString('DELIVERY-MANIFEST.json',$engine);
    }

    public function test_manifest_preflight_repairs_known_failure_classes(): void
    {
        $orchestrator=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/Orchestrator.php');
        self::assertStringContainsString('public function validateManifest',$orchestrator);
        self::assertStringContainsString('public function manifestRepairBrief',$orchestrator);
        self::assertStringContainsString('production_selected_variant',$orchestrator);
        self::assertStringContainsString('production_pdf_pages',$orchestrator);
        self::assertStringContainsString('production_customer_flag',$orchestrator);
        self::assertStringContainsString('production_pdf_capability',$orchestrator);
        self::assertStringContainsString('production_nested_zip',$orchestrator);
        self::assertStringContainsString('production_svg_content',$orchestrator);
        self::assertStringContainsString("['group'=>'evidence','assets'=>\$evidenceAssets]",$orchestrator);
        self::assertStringContainsString('evidence_assets_in_customer_package',$orchestrator);
        self::assertStringContainsString("'generator_version'=>'1.0.8'",$orchestrator);
        self::assertStringContainsString('$customerFiles=array_values(array_map',$orchestrator);
        self::assertStringNotContainsString('array_filter($productAssets,static fn(array $asset):bool=>!preg_match',$orchestrator);
    }
}
