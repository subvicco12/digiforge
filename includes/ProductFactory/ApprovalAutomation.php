<?php

declare(strict_types=1);

namespace DigiForge\ProductFactory;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use WP_Error;

/**
 * Resumable, self-healing Product Factory runtime.
 * External publishing/fulfilment remains gated elsewhere and is never invoked here.
 */
final class ApprovalAutomation
{
    private const HOOK = 'digiforge_u3_build_product';
    private const STAGE_HOOK = 'digiforge_u3_build_product_stage';
    private const MAX_STAGE_RETRIES = 3;
    private const MAX_MANIFEST_REPAIRS = 2;
    private const MAX_QA_REPAIRS = 2;
    private const MAX_RECOVERY_SECONDS = 21600;
    private const ACTIVE_LEASE_SECONDS = 1200;
    private const POLL_PAUSE_MICROSECONDS = 3000000;

    public function register(): void
    {
        add_action('digiforge_log', [$this, 'onAudit'], 20, 4);
        add_action(self::HOOK, [$this, 'run'], 10, 3);
        add_action(self::STAGE_HOOK, [$this, 'runStage'], 10, 4);
        add_filter('action_scheduler_timeout_period', [$this, 'timeoutPeriod']);
    }

    public function timeoutPeriod(int $seconds): int
    {
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
        $active = get_option($this->activeKey($candidateId, $shop), []);
        if (is_array($active) && ! empty($active['run_key']) && (time() - (int) ($active['updated_at'] ?? 0)) < self::ACTIVE_LEASE_SECONDS) {
            return new WP_Error('u3_run_active', __('A Product Factory run is already active for this candidate.', 'digiforge'), ['status' => 409, 'run_key' => (string) $active['run_key']]);
        }
        $runKey = 'u3-repair-candidate-' . $candidateId . '-' . $shop . '-' . gmdate('YmdHis') . '-' . wp_generate_uuid4();
        $scheduled = $this->schedule($candidateId, $shop, $runKey, true);
        if (! $scheduled) {
            return new WP_Error('u3_retry_not_scheduled', __('Product Factory retry could not be scheduled.', 'digiforge'), ['status' => 503]);
        }
        return ['candidate_id'=>$candidateId,'shop'=>$shop,'scheduled'=>true,'run_key'=>$runKey,'external_actions'=>false];
    }

    /** @return array<string,mixed>|WP_Error */
    public function resumeFailed(int $candidateId): array|WP_Error
    {
        if ($candidateId < 1) {
            return new WP_Error('invalid_candidate', __('Candidate ID is invalid.', 'digiforge'), ['status' => 400]);
        }
        $shop = $this->resolveShop($candidateId);
        if ($shop === '') {
            return new WP_Error('target_shop_unknown', __('The approved candidate does not have a valid target shop.', 'digiforge'), ['status' => 409]);
        }
        global $wpdb;
        $status = strtoupper((string) $wpdb->get_var($wpdb->prepare(
            'SELECT review_status FROM ' . Tables::research_candidates() . ' WHERE id=%d LIMIT 1',
            $candidateId
        )));
        if ($status !== 'APPROVED') {
            return new WP_Error('candidate_not_approved', __('Only an approved research candidate may resume Product Factory.', 'digiforge'), ['status' => 409]);
        }
        $activeKey = $this->activeKey($candidateId, $shop);
        $active = get_option($activeKey, []);
        $runKey = is_array($active) ? sanitize_key((string) ($active['run_key'] ?? '')) : '';
        $stateKey = $runKey !== '' ? $this->stateKey($runKey) : '';
        $state = $stateKey !== '' ? get_option($stateKey, []) : [];
        if ($runKey === '') {
            $checkpoint = $this->terminalCheckpoint($candidateId, $shop);
            if (is_wp_error($checkpoint)) { return $checkpoint; }
            $runKey = (string) $checkpoint['run_key'];
            $stateKey = (string) $checkpoint['state_key'];
            $state = (array) $checkpoint['state'];
        }
        if (! is_array($state)) {
            return new WP_Error('u3_resume_not_failed', __('The persisted Product Factory run is not in a failed resumable state.', 'digiforge'), ['status' => 409]);
        }
        $previousError = is_array($state['terminal_error'] ?? null) ? (array) $state['terminal_error'] : [];
        if ($previousError === []) {
            $stage = sanitize_key((string) ($state['stage'] ?? ''));
            $orphan = $this->orphanedFailedStage($candidateId, $shop, $runKey, $stage);
            if (is_wp_error($orphan)) { return $orphan; }
            $previousError = ['code'=>'u3_orphaned_failed_action','message'=>'Recovered from an Action Scheduler failure that predated terminal-error checkpointing.','action_id'=>(int)$orphan];
        }
        $createdAt = (int) ($state['created_at'] ?? 0);
        $expiredTerminal = $createdAt > 0 && (time() - $createdAt) > self::MAX_RECOVERY_SECONDS && is_array($state['terminal_error'] ?? null);
        if ($createdAt < 1 || ((time() - $createdAt) > self::MAX_RECOVERY_SECONDS && ! $expiredTerminal)) {
            return new WP_Error('u3_resume_expired', __('The persisted Product Factory run is outside the safe recovery window.', 'digiforge'), ['status' => 409]);
        }
        $stage = sanitize_key((string) ($state['stage'] ?? ''));
        if ($expiredTerminal) {
            $lease = $this->expiredTerminalLease($candidateId, $shop, $runKey, $stage);
            if (is_wp_error($lease)) { return $lease; }
            $state['created_at'] = time();
            $state['recovery_origin_created_at'] = $createdAt;
            $state['recovery_lease_started_at'] = time();
        }
        if (! in_array($stage, ['develop', 'development_poll', 'manifest_poll', 'finalize'], true)) {
            return new WP_Error('u3_resume_invalid_stage', __('The persisted Product Factory stage cannot be resumed safely.', 'digiforge'), ['status' => 409]);
        }
        unset($state['terminal_error']);
        $state['updated_at'] = time();
        if (! update_option($stateKey, $state, false)) {
            $state['terminal_error'] = $previousError;
            return new WP_Error('u3_resume_checkpoint_failed', __('Unable to persist the Product Factory resume checkpoint.', 'digiforge'), ['status' => 500]);
        }
        if (! $this->scheduleStage($candidateId, $shop, $runKey, $stage)) {
            $state['terminal_error'] = $previousError;
            $state['updated_at'] = time();
            update_option($stateKey, $state, false);
            return new WP_Error('u3_resume_not_scheduled', __('The failed Product Factory stage could not be rescheduled.', 'digiforge'), ['status' => 503]);
        }
        $this->touchActive($candidateId, $shop, $runKey, $stage);
        Logger::audit('u3_product_build_resumed', ['run_key'=>$runKey,'stage'=>$stage,'previous_error_code'=>(string)($previousError['code']??''),'external_actions'=>false], 'research_candidate', (string) $candidateId);
        return ['candidate_id'=>$candidateId,'shop'=>$shop,'resumed'=>true,'run_key'=>$runKey,'stage'=>$stage,'external_actions'=>false];
    }

    /** @return array{run_key:string,state_key:string,state:array<string,mixed>}|WP_Error */
    private function terminalCheckpoint(int $candidateId, string $shop): array|WP_Error
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('digiforge_u3_') . '%'
        ), ARRAY_A);
        $matches = [];
        foreach ((array) $rows as $row) {
            $name = (string) ($row['option_name'] ?? '');
            if (str_starts_with($name, 'digiforge_u3_active_')) { continue; }
            $state = maybe_unserialize($row['option_value'] ?? '');
            if (! is_array($state) || ! is_array($state['terminal_error'] ?? null)) { continue; }
            $developed = is_array($state['developed'] ?? null) ? $state['developed'] : [];
            if ((int) ($developed['product']['id'] ?? 0) < 1 || sanitize_key((string) ($developed['shop'] ?? '')) !== $shop) { continue; }
            $audit = $wpdb->get_var($wpdb->prepare(
                'SELECT context FROM ' . Tables::audit_log() . " WHERE event_type='u3_product_build_failed' AND object_type='research_candidate' AND object_id=%s ORDER BY id DESC LIMIT 1",
                (string) $candidateId
            ));
            $context = is_string($audit) ? json_decode($audit, true) : null;
            $runKey = is_array($context) ? sanitize_key((string) ($context['run_key'] ?? '')) : '';
            if ($runKey === '' || $this->stateKey($runKey) !== $name) { continue; }
            $matches[] = ['run_key'=>$runKey,'state_key'=>$name,'state'=>$state];
        }
        if (count($matches) !== 1) {
            return new WP_Error('u3_resume_missing_run', __('No unique persisted failed Product Factory checkpoint is available to resume.', 'digiforge'), ['status' => 409]);
        }
        return $matches[0];
    }

    private function expiredTerminalLease(int $candidateId, string $shop, string $runKey, string $stage): true|WP_Error
    {
        if (! in_array($stage, ['develop', 'development_poll', 'manifest_poll', 'finalize'], true)) {
            return new WP_Error('u3_resume_invalid_stage', __('The expired Product Factory checkpoint is not at a resumable stage.', 'digiforge'), ['status' => 409]);
        }
        if (! function_exists('as_get_scheduled_actions')) {
            return new WP_Error('u3_resume_evidence_unavailable', __('Action Scheduler evidence is unavailable; refusing expired checkpoint recovery.', 'digiforge'), ['status' => 409]);
        }
        $args = [$candidateId, $shop, $runKey, $stage];
        foreach ([\ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING] as $status) {
            if (as_get_scheduled_actions(['hook'=>self::STAGE_HOOK,'args'=>$args,'status'=>$status,'per_page'=>1]) !== []) {
                return new WP_Error('u3_resume_stage_active', __('The expired Product Factory stage already has active scheduler work.', 'digiforge'), ['status' => 409]);
            }
        }
        Logger::audit('u3_product_build_recovery_lease_renewed', ['run_key'=>$runKey,'stage'=>$stage,'external_actions'=>false], 'research_candidate', (string) $candidateId);
        return true;
    }

    private function orphanedFailedStage(int $candidateId, string $shop, string $runKey, string $stage): int|WP_Error
    {
        if (! in_array($stage, ['develop', 'development_poll', 'manifest_poll', 'finalize'], true)) {
            return new WP_Error('u3_resume_not_failed', __('The persisted Product Factory run is not in a failed resumable state.', 'digiforge'), ['status' => 409]);
        }
        if (! function_exists('as_get_scheduled_actions')) {
            return new WP_Error('u3_resume_evidence_unavailable', __('Action Scheduler evidence is unavailable; refusing orphan recovery.', 'digiforge'), ['status' => 409]);
        }
        $args = [$candidateId, $shop, $runKey, $stage];
        $pending = as_get_scheduled_actions(['hook'=>self::STAGE_HOOK,'args'=>$args,'status'=>\ActionScheduler_Store::STATUS_PENDING,'per_page'=>1]);
        $running = as_get_scheduled_actions(['hook'=>self::STAGE_HOOK,'args'=>$args,'status'=>\ActionScheduler_Store::STATUS_RUNNING,'per_page'=>1]);
        if ($pending !== [] || $running !== []) {
            return new WP_Error('u3_resume_stage_active', __('The persisted Product Factory stage still has active scheduler work.', 'digiforge'), ['status' => 409]);
        }
        $failed = as_get_scheduled_actions(['hook'=>self::STAGE_HOOK,'args'=>$args,'status'=>\ActionScheduler_Store::STATUS_FAILED,'orderby'=>'date','order'=>'DESC','per_page'=>1]);
        if ($failed === []) {
            return new WP_Error('u3_resume_not_failed', __('No matching failed Action Scheduler stage proves this run is resumable.', 'digiforge'), ['status' => 409]);
        }
        $ids = array_keys($failed);
        $actionId = (int) reset($ids);
        if ($actionId < 1) {
            return new WP_Error('u3_resume_evidence_invalid', __('Failed Action Scheduler evidence is invalid.', 'digiforge'), ['status' => 409]);
        }
        return $actionId;
    }

    public function run(int $candidateId, string $shop, string $runKey = ''): void
    {
        $shop = sanitize_key($shop);
        if (! in_array($shop, ['digital', 'goods'], true)) {
            Logger::audit('u3_product_build_failed', ['reason' => 'invalid_shop'], 'research_candidate', (string) $candidateId);
            return;
        }
        $runKey = sanitize_key($runKey);
        if ($runKey === '') { $runKey = 'u3-auto-candidate-' . $candidateId . '-' . $shop; }
        $this->touchActive($candidateId, $shop, $runKey, 'dispatcher');
        if (! $this->scheduleStage($candidateId, $shop, $runKey, 'develop')) {
            $this->terminalError($candidateId, $shop, $runKey, new WP_Error('digiforge_u3_scheduler', 'Unable to schedule Product Factory development stage.'));
        }
    }

    public function runStage(int $candidateId, string $shop, string $runKey = '', string $stage = 'develop'): void
    {
        $shop=sanitize_key($shop);$runKey=sanitize_key($runKey);$stage=sanitize_key($stage);
        if($candidateId<1||!in_array($shop,['digital','goods'],true)||$runKey===''){
            Logger::audit('u3_product_build_failed',['reason'=>'invalid_stage_args'],'research_candidate',(string)$candidateId);return;
        }
        if(function_exists('set_time_limit')){@set_time_limit(180);}
        $stateKey=$this->stateKey($runKey);
        $state=get_option($stateKey,[]);$state=is_array($state)?$state:[];
        $createdAt=(int)($state['created_at']??time());
        if((time()-$createdAt)>self::MAX_RECOVERY_SECONDS){
            $this->terminalError($candidateId,$shop,$runKey,new WP_Error('digiforge_u3_runtime_expired','Product Factory run exceeded the safe recovery window.'),$stateKey,$state);return;
        }
        $state['created_at']=$createdAt;$state['stage']=$stage;$state['updated_at']=time();update_option($stateKey,$state,false);
        $this->touchActive($candidateId,$shop,$runKey,$stage);
        $orchestrator=new Orchestrator();

        if($stage==='develop'){
            $engine=new \DigiForge\Launch\ExecutionEngine();
            $brief=$engine->developmentBrief($candidateId,$shop);
            if(is_wp_error($brief)){$this->terminalError($candidateId,$shop,$runKey,$brief,$stateKey,$state);return;}
            $started=(new \DigiForge\Launch\OpenAIClient())->startBackgroundDevelop($brief,8000);
            if(is_wp_error($started)){
                if($this->retryStage($candidateId,$shop,$runKey,'develop',$started,$stateKey,$state,'development_start_retries'))return;
                $this->terminalError($candidateId,$shop,$runKey,$started,$stateKey,$state);return;
            }
            $state['development_response_id']=(string)$started['response_id'];$state['development_started_at']=time();
            update_option($stateKey,$state,false);
            $this->scheduleStage($candidateId,$shop,$runKey,'development_poll');
            Logger::audit('u3_product_build_stage_completed',['stage'=>'develop','next_stage'=>'development_poll','external_actions'=>false],'research_candidate',(string)$candidateId);return;
        }

        if($stage==='development_poll'){
            $responseId=sanitize_text_field((string)($state['development_response_id']??''));
            if($responseId===''){$this->terminalError($candidateId,$shop,$runKey,new WP_Error('digiforge_u3_missing_development_response','Missing background development response.'),$stateKey,$state);return;}
            $development=(new \DigiForge\Launch\OpenAIClient())->retrieveBackground($responseId);
            if(is_wp_error($development)){
                if($this->retryStage($candidateId,$shop,$runKey,'develop',$development,$stateKey,$state,'development_poll_retries',['development_response_id']))return;
                $this->terminalError($candidateId,$shop,$runKey,$development,$stateKey,$state);return;
            }
            if(($development['status']??'')!=='completed'){$this->pauseAndSchedule($candidateId,$shop,$runKey,'development_poll');return;}
            $developmentKey=str_starts_with($runKey,'u3-repair-')?$runKey.'-development':'u3-auto-candidate-'.$candidateId.'-'.$shop.'-development';
            $developed=(new \DigiForge\Launch\ExecutionEngine())->persistDevelopment($candidateId,$shop,$developmentKey,$development);
            if(is_wp_error($developed)){
                if($this->retryStage($candidateId,$shop,$runKey,'development_poll',$developed,$stateKey,$state,'development_persist_retries'))return;
                $this->terminalError($candidateId,$shop,$runKey,$developed,$stateKey,$state);return;
            }
            $started=(new \DigiForge\Launch\OpenAIClient())->startBackgroundDevelop($orchestrator->manifestBrief($developed,$shop),16000);
            if(is_wp_error($started)){
                if($this->retryStage($candidateId,$shop,$runKey,'development_poll',$started,$stateKey,$state,'manifest_start_retries'))return;
                $this->terminalError($candidateId,$shop,$runKey,$started,$stateKey,$state);return;
            }
            $state['developed']=$developed;$state['manifest_response_id']=(string)$started['response_id'];$state['manifest_started_at']=time();
            update_option($stateKey,$state,false);
            $this->scheduleStage($candidateId,$shop,$runKey,'manifest_poll');
            Logger::audit('u3_product_build_stage_completed',['stage'=>'development_poll','next_stage'=>'manifest_poll','external_actions'=>false],'research_candidate',(string)$candidateId);return;
        }

        if($stage==='manifest_poll'){
            $developed=is_array($state['developed']??null)?$state['developed']:[];
            $responseId=sanitize_text_field((string)($state['manifest_response_id']??''));
            if($developed===[]||$responseId===''){$this->terminalError($candidateId,$shop,$runKey,new WP_Error('digiforge_u3_missing_manifest_state','Missing resumable manifest state.'),$stateKey,$state);return;}
            $manifest=(new \DigiForge\Launch\OpenAIClient())->retrieveBackground($responseId);
            if(is_wp_error($manifest)){
                if($this->restartManifest($candidateId,$shop,$runKey,$developed,$manifest,$stateKey,$state,$orchestrator))return;
                $this->terminalError($candidateId,$shop,$runKey,$manifest,$stateKey,$state);return;
            }
            if(($manifest['status']??'')!=='completed'){$this->pauseAndSchedule($candidateId,$shop,$runKey,'manifest_poll');return;}
            $payload=is_array($manifest['payload']??null)?$manifest['payload']:[];
            $preflight=$orchestrator->validateManifest($developed,$payload);
            if(is_wp_error($preflight)){
                if($this->restartManifest($candidateId,$shop,$runKey,$developed,$preflight,$stateKey,$state,$orchestrator,$payload))return;
                $this->terminalError($candidateId,$shop,$runKey,$preflight,$stateKey,$state);return;
            }
            $state['manifest_ai']=$manifest;update_option($stateKey,$state,false);
            $this->scheduleStage($candidateId,$shop,$runKey,'finalize');
            Logger::audit('u3_product_build_stage_completed',['stage'=>'manifest_poll','next_stage'=>'finalize','external_actions'=>false],'research_candidate',(string)$candidateId);return;
        }

        if($stage!=='finalize'||!is_array($state['developed']??null)||!is_array($state['manifest_ai']??null)){
            $this->terminalError($candidateId,$shop,$runKey,new WP_Error('digiforge_u3_invalid_resume_state','Product Factory resume state is invalid.'),$stateKey,$state);return;
        }

        $buildKey=sanitize_key((string)($state['build_key']??$runKey));if($buildKey==='')$buildKey=$runKey;
        if (is_array($state['terminal_error'] ?? null)
            && (string) ($state['terminal_error']['code'] ?? '') === 'digiforge_u3_asset_replay_conflict') {
            $generation = max(1, (int) ($state['replay_generation'] ?? 0) + 1);
            $state['replay_generation'] = $generation;
            $buildKey = sanitize_key($runKey . '-generation-' . $generation);
            $state['build_key'] = $buildKey;
            update_option($stateKey, $state, false);
            Logger::audit('u3_product_build_replay_generation_created', ['run_key'=>$runKey,'build_key'=>$buildKey,'generation'=>$generation,'external_actions'=>false], 'research_candidate', (string) $candidateId);
        }
        $result=$orchestrator->build($candidateId,['shop'=>$shop,'_developed'=>$state['developed'],'_manifest_ai'=>$state['manifest_ai']],$buildKey);
        if(is_wp_error($result)){
            if($this->restartManifest($candidateId,$shop,$runKey,$state['developed'],$result,$stateKey,$state,$orchestrator,is_array($state['manifest_ai']['payload']??null)?$state['manifest_ai']['payload']:[]))return;
            $this->terminalError($candidateId,$shop,$runKey,$result,$stateKey,$state);return;
        }

        if(($result['qa_passed']??false)!==true){
            $qaRepairs=(int)($state['qa_repair_attempts']??0);
            if($qaRepairs<self::MAX_QA_REPAIRS){
                $qaRepairs++;$issues=$this->qaIssues($result);
                $brief=$orchestrator->manifestRepairBrief($state['developed'],$shop,$issues,[]);
                $started=(new \DigiForge\Launch\OpenAIClient())->startBackgroundDevelop($brief,16000);
                if(!is_wp_error($started)){
                    $state['qa_repair_attempts']=$qaRepairs;$state['manifest_response_id']=(string)$started['response_id'];unset($state['manifest_ai']);
                    $state['build_key']=$runKey.'-qa-repair-'.$qaRepairs;update_option($stateKey,$state,false);
                    Logger::audit('u3_product_build_auto_repair_scheduled',['reason'=>'qa_failed','repair_attempt'=>$qaRepairs,'issues'=>$issues,'external_actions'=>false],'research_candidate',(string)$candidateId);
                    $this->scheduleStage($candidateId,$shop,$runKey,'manifest_poll');return;
                }
            }
            delete_option($stateKey);$this->clearActive($candidateId,$shop,$runKey);
            Logger::audit('u3_product_build_completed',['shop'=>$shop,'run_key'=>$runKey,'product_version_id'=>(int)($result['product_version']['id']??0),'workflow_status'=>(string)($result['workflow_status']??'QA_FAILED'),'product_approval_required'=>false,'auto_repair_exhausted'=>true,'external_actions'=>false],'research_candidate',(string)$candidateId);return;
        }

        delete_option($stateKey);$this->clearActive($candidateId,$shop,$runKey);
        Logger::audit('u3_product_build_completed',['shop'=>$shop,'run_key'=>$runKey,'product_version_id'=>(int)($result['product_version']['id']??0),'workflow_status'=>(string)($result['workflow_status']??''),'product_approval_required'=>(bool)($result['product_approval_required']??false),'external_actions'=>false],'research_candidate',(string)$candidateId);
    }

    /** @param array<string,mixed> $state @param list<string> $clear */
    private function retryStage(int $candidateId,string $shop,string $runKey,string $stage,WP_Error $error,string $stateKey,array $state,string $counter,array $clear=[]):bool
    {
        if(!$this->retryable($error))return false;
        $attempt=(int)($state[$counter]??0);
        if($attempt>=self::MAX_STAGE_RETRIES)return false;
        $attempt++;$state[$counter]=$attempt;foreach($clear as$key)unset($state[$key]);update_option($stateKey,$state,false);
        Logger::audit('u3_product_build_auto_retry',['stage'=>$stage,'attempt'=>$attempt,'error_code'=>$error->get_error_code(),'external_actions'=>false],'research_candidate',(string)$candidateId);
        usleep(self::POLL_PAUSE_MICROSECONDS);
        return $this->scheduleStage($candidateId,$shop,$runKey,$stage);
    }

    /** @param array<string,mixed> $developed @param array<string,mixed> $state @param array<string,mixed> $badPayload */
    private function restartManifest(int $candidateId,string $shop,string $runKey,array $developed,WP_Error $error,string $stateKey,array $state,Orchestrator $orchestrator,array $badPayload=[]):bool
    {
        $attempt=(int)($state['manifest_repair_attempts']??0);
        $structural=str_starts_with($error->get_error_code(),'digiforge_production_')||str_starts_with($error->get_error_code(),'digiforge_u3_');
        if(!$structural&&!$this->retryable($error))return false;
        if($attempt>=self::MAX_MANIFEST_REPAIRS)return false;
        $attempt++;$issues=[$error->get_error_code().': '.$error->get_error_message()];
        $brief=$orchestrator->manifestRepairBrief($developed,$shop,implode("\n",$issues),$badPayload);
        $started=(new \DigiForge\Launch\OpenAIClient())->startBackgroundDevelop($brief,16000);
        if(is_wp_error($started))return false;
        $state['manifest_repair_attempts']=$attempt;$state['manifest_response_id']=(string)$started['response_id'];unset($state['manifest_ai']);
        $state['build_key']=$runKey.'-manifest-repair-'.$attempt;update_option($stateKey,$state,false);
        Logger::audit('u3_product_build_auto_repair_scheduled',['reason'=>'manifest_preflight','repair_attempt'=>$attempt,'error_code'=>$error->get_error_code(),'external_actions'=>false],'research_candidate',(string)$candidateId);
        return $this->scheduleStage($candidateId,$shop,$runKey,'manifest_poll');
    }

    /** @param array<string,mixed> $result @return list<string> */
    private function qaIssues(array $result):array
    {
        $issues=[];$qa=is_array($result['qa']??null)?$result['qa']:[];
        foreach($qa as$row){if(is_array($row)&&strtoupper((string)($row['status']??''))!=='PASS')$issues[]=sanitize_text_field((string)($row['check_name']??'QA check')).': '.sanitize_text_field((string)($row['details']??'failed'));}
        return $issues===[]?['Semantic QA did not pass.']:$issues;
    }

    private function pauseAndSchedule(int $candidateId,string $shop,string $runKey,string $stage):void
    {
        usleep(self::POLL_PAUSE_MICROSECONDS);
        $this->scheduleStage($candidateId,$shop,$runKey,$stage);
    }

    private function retryable(WP_Error $error):bool
    {
        return in_array($error->get_error_code(),[
            'digiforge_launch_ai_transport','digiforge_launch_ai_provider','digiforge_launch_ai_incomplete',
            'digiforge_launch_ai_invalid_json','digiforge_launch_ai_rate_limited','digiforge_create_failed'
        ],true);
    }

    /** @param array<string,mixed> $state */
    private function terminalError(int $candidateId,string $shop,string $runKey,WP_Error $error,string $stateKey='',array $state=[]):void
    {
        if($stateKey!==''){$state['terminal_error']=['code'=>$error->get_error_code(),'message'=>$error->get_error_message(),'at'=>time()];$state['updated_at']=time();update_option($stateKey,$state,false);}
        $this->clearActive($candidateId,$shop,$runKey);
        Logger::audit('u3_product_build_failed',['run_key'=>$runKey,'error_code'=>$error->get_error_code(),'message'=>$error->get_error_message(),'external_actions'=>false],'research_candidate',(string)$candidateId);
    }

    private function scheduleStage(int $candidateId,string $shop,string $runKey,string $stage='develop',int $delay=0):bool
    {
        $args=[$candidateId,$shop,sanitize_key($runKey),sanitize_key($stage)];$scheduled=false;$scheduler='wp_cron';$actionId=0;
        if($delay<=0&&function_exists('as_enqueue_async_action')){
            $scheduler='action_scheduler_async';$actionId=as_enqueue_async_action(self::STAGE_HOOK,$args,'digiforge',false);$scheduled=is_int($actionId)&&$actionId>0;
        }elseif(function_exists('as_schedule_single_action')){
            $scheduler='action_scheduler';$actionId=as_schedule_single_action(time()+max(0,$delay),self::STAGE_HOOK,$args,'digiforge',false);$scheduled=is_int($actionId)&&$actionId>0;
        }
        if(!$scheduled){$scheduler='wp_cron';$scheduled=wp_schedule_single_event(time()+max(1,$delay),self::STAGE_HOOK,$args,true)===true;}
        Logger::audit($scheduled?'u3_product_build_stage_scheduled':'u3_product_build_stage_not_scheduled',['shop'=>$shop,'run_key'=>$runKey,'stage'=>$stage,'scheduler'=>$scheduler,'action_id'=>$actionId,'external_actions'=>false],'research_candidate',(string)$candidateId);
        return $scheduled;
    }

    private function schedule(int $candidateId,string $shop,string $runKey,bool $retry=false):bool
    {
        $args=[$candidateId,$shop,sanitize_key($runKey)];$scheduled=false;$scheduler='wp_cron';$actionId=0;
        if(function_exists('as_enqueue_async_action')){$scheduler='action_scheduler_async';$actionId=as_enqueue_async_action(self::HOOK,$args,'digiforge',false);$scheduled=is_int($actionId)&&$actionId>0;}
        if(!$scheduled){$scheduler='wp_cron';$scheduled=wp_schedule_single_event(time()+1,self::HOOK,$args,true)===true;}
        if(!$scheduled){Logger::audit('u3_product_build_not_scheduled',['reason'=>'scheduler_rejected_job','scheduler'=>$scheduler,'shop'=>$shop,'retry'=>$retry,'run_key'=>$runKey],'research_candidate',(string)$candidateId);return false;}
        $this->touchActive($candidateId,$shop,$runKey,'scheduled');
        Logger::audit($retry?'u3_product_build_retry_scheduled':'u3_product_build_scheduled',['shop'=>$shop,'scheduler'=>$scheduler,'action_id'=>$actionId,'run_key'=>$runKey,'external_actions'=>false],'research_candidate',(string)$candidateId);return true;
    }

    private function stateKey(string $runKey):string{return 'digiforge_u3_'.substr(hash('sha256',$runKey),0,32);}
    private function activeKey(int $candidateId,string $shop):string{return 'digiforge_u3_active_'.$candidateId.'_'.sanitize_key($shop);}
    private function touchActive(int $candidateId,string $shop,string $runKey,string $stage):void{update_option($this->activeKey($candidateId,$shop),['run_key'=>$runKey,'stage'=>$stage,'updated_at'=>time()],false);}
    private function clearActive(int $candidateId,string $shop,string $runKey):void{$key=$this->activeKey($candidateId,$shop);$active=get_option($key,[]);if(!is_array($active)||($active['run_key']??'')===$runKey)delete_option($key);}

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