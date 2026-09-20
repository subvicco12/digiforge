<?php

declare(strict_types=1);

namespace DigiForge\ProductFactory;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use WP_Error;

/**
 * Continues Gate 1 approvals into U3 without another human click.
 * Only the internal AI/local-asset Product Factory is invoked.
 */
final class ApprovalAutomation
{
    private const HOOK = 'digiforge_u3_build_product';
    private const STAGE_HOOK = 'digiforge_u3_build_product_stage';

    public function register(): void
    {
        add_action('digiforge_log', [$this, 'onAudit'], 20, 4);
        add_action(self::HOOK, [$this, 'run'], 10, 3);
        add_action(self::STAGE_HOOK, [$this, 'runStage'], 10, 4);
        add_filter('action_scheduler_timeout_period', [$this, 'timeoutPeriod']);
    }

    public function timeoutPeriod(int $seconds): int
    {
        // Hostinger/Action Scheduler watchdogs may enforce a hard 300s ceiling regardless of PHP set_time_limit().
        // Keep DigiForge below that boundary so Action Scheduler does not falsely mark a healthy build failed.
        return min(max($seconds, 240), 270);
    }

    /** @param array<string,mixed> $context */
    public function onAudit(string $event, array $context, string $objectType, string $objectId): void
    {
        if ($event !== 'research_candidate_reviewed'
            || $objectType !== 'research_candidate'
            || strtoupper((string) ($context['decision'] ?? '')) !== 'APPROVED') {
            return;
        }
        $candidateId = absint($objectId);
        if ($candidateId < 1) { return; }
        $shop = $this->resolveShop($candidateId);
        if ($shop === '') {
            Logger::audit('u3_product_build_not_scheduled', ['reason' => 'target_shop_unknown'], 'research_candidate', (string) $candidateId);
            return;
        }
        $this->schedule($candidateId, $shop, 'u3-auto-candidate-' . $candidateId . '-' . $shop);
    }

    /** @return array<string,mixed>|WP_Error */
    public function retryApproved(int $candidateId): array|WP_Error
    {
        global $wpdb;
        if ($candidateId < 1) {
            return new WP_Error('invalid_candidate', __('Candidate ID is invalid.', 'digiforge'), ['status' => 400]);
        }
        $status = strtoupper((string) $wpdb->get_var($wpdb->prepare(
            'SELECT review_status FROM ' . Tables::research_candidates() . ' WHERE id=%d LIMIT 1',
            $candidateId
        )));
        if ($status !== 'APPROVED') {
            return new WP_Error('candidate_not_approved', __('Only an approved research candidate may retry Product Factory.', 'digiforge'), ['status' => 409]);
        }
        $shop = $this->resolveShop($candidateId);
        if ($shop === '') {
            return new WP_Error('target_shop_unknown', __('The approved candidate does not have a valid target shop.', 'digiforge'), ['status' => 409]);
        }
        $runKey = 'u3-repair-candidate-' . $candidateId . '-' . $shop . '-' . gmdate('YmdHis') . '-' . wp_generate_uuid4();
        $scheduled = $this->schedule($candidateId, $shop, $runKey, true);
        if (! $scheduled) {
            return new WP_Error('u3_retry_not_scheduled', __('Product Factory retry could not be scheduled.', 'digiforge'), ['status' => 503]);
        }
        return [
            'candidate_id' => $candidateId,
            'shop' => $shop,
            'scheduled' => true,
            'run_key' => $runKey,
            'external_actions' => false,
        ];
    }

    public function run(int $candidateId, string $shop, string $runKey = ''): void
    {
        $shop = sanitize_key($shop);
        if (! in_array($shop, ['digital', 'goods'], true)) {
            Logger::audit('u3_product_build_failed', ['reason' => 'invalid_shop'], 'research_candidate', (string) $candidateId);
            return;
        }
        $runKey = sanitize_key($runKey);
        if ($runKey === '') {
            $runKey = 'u3-auto-candidate-' . $candidateId . '-' . $shop;
        }
        // Keep the legacy hook as a short dispatcher. Long Product Factory work runs in
        // resumable stages so a host watchdog cannot strand the whole pipeline.
        $this->scheduleStage($candidateId, $shop, $runKey);
        return;
    }

    public function runStage(int $candidateId, string $shop, string $runKey = '', string $stage = 'develop'): void
    {
        $shop=sanitize_key($shop);$runKey=sanitize_key($runKey);$stage=sanitize_key($stage);
        if($candidateId<1||!in_array($shop,['digital','goods'],true)||$runKey===''){Logger::audit('u3_product_build_failed',['reason'=>'invalid_stage_args'],'research_candidate',(string)$candidateId);return;}
        if(function_exists('set_time_limit')){@set_time_limit(180);}
        $stateKey='digiforge_u3_'.substr(hash('sha256',$runKey),0,32);
        $state=get_option($stateKey,[]);$state=is_array($state)?$state:[];
        $orchestrator=new Orchestrator();
        if($stage==='develop'){
            $engine=new \DigiForge\Launch\ExecutionEngine();$brief=$engine->developmentBrief($candidateId,$shop);
            if(is_wp_error($brief)){$this->stageError($candidateId,$runKey,$brief);return;}
            $started=(new \DigiForge\Launch\OpenAIClient())->startBackgroundDevelop($brief,8000);
            if(is_wp_error($started)){$this->stageError($candidateId,$runKey,$started);return;}
            update_option($stateKey,['development_response_id'=>(string)$started['response_id'],'created_at'=>time()],false);
            $this->scheduleStage($candidateId,$shop,$runKey,'development_poll',15);
            Logger::audit('u3_product_build_stage_completed',['stage'=>'develop','next_stage'=>'development_poll','external_actions'=>false],'research_candidate',(string)$candidateId);return;
        }
        if($stage==='development_poll'){
            $responseId=sanitize_text_field((string)($state['development_response_id']??''));if($responseId===''){Logger::audit('u3_product_build_failed',['reason'=>'missing_development_response'],'research_candidate',(string)$candidateId);return;}
            $development=(new \DigiForge\Launch\OpenAIClient())->retrieveBackground($responseId);if(is_wp_error($development)){$this->stageError($candidateId,$runKey,$development);return;}
            if(($development['status']??'')!=='completed'){$this->scheduleStage($candidateId,$shop,$runKey,'development_poll',20);return;}
            $developmentKey=str_starts_with($runKey,'u3-repair-')?$runKey.'-development':'u3-auto-candidate-'.$candidateId.'-'.$shop.'-development';
            $developed=(new \DigiForge\Launch\ExecutionEngine())->persistDevelopment($candidateId,$shop,$developmentKey,$development);if(is_wp_error($developed)){$this->stageError($candidateId,$runKey,$developed);return;}
            $started=(new \DigiForge\Launch\OpenAIClient())->startBackgroundDevelop($orchestrator->manifestBrief($developed,$shop),16000);if(is_wp_error($started)){$this->stageError($candidateId,$runKey,$started);return;}
            $state['developed']=$developed;$state['manifest_response_id']=(string)$started['response_id'];update_option($stateKey,$state,false);
            $this->scheduleStage($candidateId,$shop,$runKey,'manifest_poll',15);Logger::audit('u3_product_build_stage_completed',['stage'=>'development_poll','next_stage'=>'manifest_poll','external_actions'=>false],'research_candidate',(string)$candidateId);return;
        }
        if($stage==='manifest_poll'){
            $responseId=sanitize_text_field((string)($state['manifest_response_id']??''));
            if($responseId===''){Logger::audit('u3_product_build_failed',['reason'=>'missing_manifest_response'],'research_candidate',(string)$candidateId);return;}
            $manifest=(new \DigiForge\Launch\OpenAIClient())->retrieveBackground($responseId);
            if(is_wp_error($manifest)){$this->stageError($candidateId,$runKey,$manifest);return;}
            if(($manifest['status']??'')!=='completed'){$this->scheduleStage($candidateId,$shop,$runKey,'manifest_poll',20);return;}
            $state['manifest_ai']=$manifest;update_option($stateKey,$state,false);
            $this->scheduleStage($candidateId,$shop,$runKey,'finalize',1);
            Logger::audit('u3_product_build_stage_completed',['stage'=>'manifest_poll','next_stage'=>'finalize','external_actions'=>false],'research_candidate',(string)$candidateId);return;
        }
        if($stage!=='finalize'||!is_array($state['developed']??null)||!is_array($state['manifest_ai']??null)){Logger::audit('u3_product_build_failed',['reason'=>'invalid_resume_state','stage'=>$stage],'research_candidate',(string)$candidateId);return;}
        $result=$orchestrator->build($candidateId,['shop'=>$shop,'_developed'=>$state['developed'],'_manifest_ai'=>$state['manifest_ai']],$runKey);
        if(is_wp_error($result)){$this->stageError($candidateId,$runKey,$result);return;}
        delete_option($stateKey);
        Logger::audit('u3_product_build_completed',['shop'=>$shop,'run_key'=>$runKey,'product_version_id'=>(int)(($result['product_version']['id']??0)),'workflow_status'=>(string)($result['workflow_status']??''),'product_approval_required'=>(bool)($result['product_approval_required']??false),'external_actions'=>false],'research_candidate',(string)$candidateId);
    }

    private function stageError(int $candidateId,string $runKey,WP_Error $error):void
    {Logger::audit('u3_product_build_failed',['run_key'=>$runKey,'error_code'=>$error->get_error_code(),'message'=>$error->get_error_message()],'research_candidate',(string)$candidateId);}

    private function scheduleStage(int $candidateId,string $shop,string $runKey,string $stage='develop',int $delay=0):bool
    {
        $args=[$candidateId,$shop,sanitize_key($runKey),sanitize_key($stage)];$when=time()+max(0,$delay);
        if(function_exists('as_schedule_single_action')){$actionId=as_schedule_single_action($when,self::STAGE_HOOK,$args,'digiforge',false);$scheduled=is_int($actionId)&&$actionId>0;}else{$scheduled=wp_schedule_single_event($when,self::STAGE_HOOK,$args,true)===true;}
        Logger::audit($scheduled?'u3_product_build_stage_scheduled':'u3_product_build_stage_not_scheduled',['shop'=>$shop,'run_key'=>$runKey,'stage'=>$stage,'external_actions'=>false],'research_candidate',(string)$candidateId);return$scheduled;
    }

    private function schedule(int $candidateId, string $shop, string $runKey, bool $retry = false): bool
    {
        $args = [$candidateId, $shop, sanitize_key($runKey)];
        $scheduled = false;
        $scheduler = 'wp_cron';
        if (function_exists('as_enqueue_async_action')) {
            $scheduler = 'action_scheduler';
            $actionId = as_enqueue_async_action(self::HOOK, $args, 'digiforge', true);
            $scheduled = is_int($actionId) && $actionId > 0;
        } else {
            $scheduled = wp_schedule_single_event(time() + 1, self::HOOK, $args, true) === true;
        }
        if (! $scheduled) {
            Logger::audit('u3_product_build_not_scheduled', [
                'reason' => 'scheduler_rejected_job',
                'scheduler' => $scheduler,
                'shop' => $shop,
                'retry' => $retry,
                'run_key' => $runKey,
            ], 'research_candidate', (string) $candidateId);
            return false;
        }
        Logger::audit($retry ? 'u3_product_build_retry_scheduled' : 'u3_product_build_scheduled', [
            'shop' => $shop,
            'scheduler' => $scheduler,
            'run_key' => $runKey,
            'external_actions' => false,
        ], 'research_candidate', (string) $candidateId);
        return true;
    }

    private function resolveShop(int $candidateId): string
    {
        global $wpdb;
        $config = $wpdb->get_var($wpdb->prepare(
            'SELECT s.config FROM ' . Tables::research_candidate_evidence() . ' ce '
            . 'INNER JOIN ' . Tables::research_evidence() . ' e ON e.id=ce.evidence_id '
            . 'INNER JOIN ' . Tables::research_observations() . ' o ON o.id=e.observation_id '
            . 'INNER JOIN ' . Tables::research_sources() . ' s ON s.id=o.source_id '
            . 'WHERE ce.candidate_id=%d ORDER BY ce.evidence_id ASC LIMIT 1',
            $candidateId
        ));
        $decoded = is_string($config) ? json_decode($config, true) : null;
        $shop = is_array($decoded) ? sanitize_key((string) ($decoded['shop'] ?? '')) : '';
        return in_array($shop, ['digital', 'goods'], true) ? $shop : '';
    }
}
