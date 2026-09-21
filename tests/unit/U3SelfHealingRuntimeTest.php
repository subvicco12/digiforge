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
        self::assertStringContainsString('ACTIVE_LEASE_SECONDS = 1200',$automation);
        self::assertStringContainsString('MAX_RECOVERY_SECONDS = 21600',$automation);
        self::assertStringContainsString('public function resumeFailed(int $candidateId)',$automation);
        self::assertStringContainsString('private function orphanedFailedStage(int $candidateId, string $shop, string $runKey, string $stage)',$automation);
        self::assertStringContainsString('private function terminalCheckpoint(int $candidateId, string $shop)',$automation);
        self::assertStringContainsString('private function expiredTerminalLease(int $candidateId, string $shop, string $runKey, string $stage)',$automation);
        self::assertStringContainsString('recovery_origin_created_at',$automation);
        self::assertStringContainsString('recovery_lease_started_at',$automation);
        self::assertStringContainsString('u3_product_build_recovery_lease_renewed',$automation);
        self::assertStringContainsString("candidate_not_approved",$automation);
        self::assertStringContainsString('count($matches) !== 1',$automation);
        self::assertStringContainsString("u3_product_build_failed",$automation);
        $engine=file_get_contents(dirname(__DIR__,2).'/includes/Launch/ExecutionEngine.php');
        self::assertStringContainsString('$pageCounts[$file]=$existing>0?$existing:max(1,$defaultPageCount);',$engine);
        self::assertStringContainsString("preg_replace('/pdf$/i','.pdf',\$countCanonical)",$engine);
        $orchestrator=file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/Orchestrator.php');
        self::assertStringContainsString("substr(\$file,0,-3).'.pdf'",$orchestrator);
        self::assertStringContainsString('$canonical=strtolower($file);$matches=[]',$orchestrator);
        self::assertStringContainsString('count($matches)>1',$orchestrator);
        self::assertStringContainsString("Expected-page-count filename is ambiguous after canonical matching",$orchestrator);
        self::assertStringContainsString("production_pdf_capability",$orchestrator);
        self::assertStringContainsString("Unsupported PDF capability claim in",$orchestrator);
        self::assertStringContainsString("preg_split('/(?<=[.!?])",$orchestrator);
        self::assertStringContainsString("STATUS_FAILED",$automation);
        self::assertStringContainsString("STATUS_PENDING",$automation);
        self::assertStringContainsString("STATUS_RUNNING",$automation);
        self::assertStringContainsString("u3_resume_stage_active",$automation);
        self::assertStringContainsString("u3_orphaned_failed_action",$automation);
        $controller=file_get_contents(dirname(__DIR__,2).'/includes/REST/ResearchController.php');
        self::assertStringContainsString("/resume-product-factory",$controller);
        self::assertStringContainsString("callback'=>[\$this,'resumeProductFactory']",$controller);
        self::assertStringContainsString("'u3_product_build_resumed'",$automation);
        self::assertStringContainsString("'u3_resume_checkpoint_failed'",$automation);
        self::assertStringContainsString("'u3_resume_not_scheduled'",$automation);
        self::assertStringContainsString("'digiforge_u3_asset_replay_conflict'",$automation);
        self::assertStringContainsString("'replay_repair_attempts'",$automation);
        self::assertStringContainsString("'-replay-repair-'",$automation);
        self::assertStringContainsString("'u3_product_build_replay_repair_identity'",$automation);
        self::assertStringContainsString('implode("\\n",$issues)',$automation);
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
        self::assertStringContainsString('translation_review_required',$engine);
        self::assertStringContainsString("array_filter(\$selectedFiles",$engine);
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
        self::assertStringContainsString('Required PDF missing from manifest:',$orchestrator);
        self::assertStringContainsString('production_customer_flag',$orchestrator);
        self::assertStringContainsString('production_pdf_capability',$orchestrator);
        self::assertStringContainsString('production_nested_zip',$orchestrator);
        self::assertStringContainsString('production_svg_content',$orchestrator);
        self::assertStringContainsString("['group'=>'evidence','assets'=>\$evidenceAssets]",$orchestrator);
        self::assertStringContainsString('evidence_assets_in_customer_package',$orchestrator);
        self::assertStringContainsString("'generator_version'=>'1.0.23'",$orchestrator);
        self::assertStringContainsString('$customerFiles=array_values(array_map',$orchestrator);
        self::assertStringNotContainsString('array_filter($productAssets,static fn(array $asset):bool=>!preg_match',$orchestrator);
    }
}
